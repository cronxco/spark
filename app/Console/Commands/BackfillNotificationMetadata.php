<?php

namespace App\Console\Commands;

use App\Notifications\CookiesAutoRefreshed;
use App\Notifications\DailyDigestReady;
use App\Notifications\IntegrationAuthenticationFailed;
use App\Notifications\IntegrationCompleted;
use App\Notifications\IntegrationFailed;
use App\Notifications\MigrationCompleted;
use App\Notifications\MigrationFailed;
use App\Notifications\NotificationCatalogue;
use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

class BackfillNotificationMetadata extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:backfill-metadata
        {--dry-run : Report changes without writing them}
        {--chunk=500 : Number of notifications to process per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill stable notification types and safely collapse repeated incidents';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, min((int) $this->option('chunk'), 5_000));
        $processed = 0;
        $updated = 0;
        $collapsed = 0;
        $skipped = 0;
        $dryRunGroups = [];

        DatabaseNotification::query()->chunkById($chunkSize, function ($notifications) use (
            $dryRun,
            &$processed,
            &$updated,
            &$collapsed,
            &$skipped,
            &$dryRunGroups,
        ) {
            foreach ($notifications as $notification) {
                $processed++;
                $data = is_array($notification->data) ? $notification->data : [];
                $type = $this->stableType((string) $notification->type, $data);
                if ($type === null) {
                    $skipped++;

                    continue;
                }

                $groupKey = $this->groupKey($type, $data);
                $payload = [
                    ...$data,
                    'contract_version' => 1,
                    'type' => $type,
                    'stream' => NotificationCatalogue::streamFor($type),
                    'severity' => NotificationCatalogue::severityFor($type),
                    'body' => $data['body'] ?? $data['message'] ?? $data['headline'] ?? null,
                    'occurrence_count' => max(1, (int) ($data['occurrence_count'] ?? 1)),
                ];

                if ($dryRun) {
                    $key = implode('|', [$notification->notifiable_type, $notification->notifiable_id, $groupKey ?? $notification->id]);
                    if ($groupKey !== null && isset($dryRunGroups[$key])) {
                        $collapsed++;
                    } else {
                        $dryRunGroups[$key] = true;
                    }
                    if ($notification->type !== $type || $notification->group_key !== $groupKey || $payload !== $data) {
                        $updated++;
                    }

                    continue;
                }

                DB::transaction(function () use ($notification, $type, $groupKey, $payload, &$updated, &$collapsed) {
                    $current = DatabaseNotification::query()->lockForUpdate()->find($notification->id);
                    if ($current === null) {
                        return;
                    }

                    if ($groupKey !== null && $current->archived_at === null) {
                        $existing = DatabaseNotification::query()
                            ->where('notifiable_type', $current->notifiable_type)
                            ->where('notifiable_id', $current->notifiable_id)
                            ->where('group_key', $groupKey)
                            ->whereNull('archived_at')
                            ->whereKeyNot($current->id)
                            ->lockForUpdate()
                            ->first();

                        if ($existing !== null) {
                            $existingData = is_array($existing->data) ? $existing->data : [];
                            $combinedCount = max(1, (int) ($existingData['occurrence_count'] ?? 1))
                                + max(1, (int) ($payload['occurrence_count'] ?? 1));

                            if ($current->created_at->gt($existing->created_at)) {
                                $existing->forceFill([
                                    'archived_at' => now(),
                                    'data' => [...$existingData, 'archive_reason' => 'superseded'],
                                ])->save();
                                $payload['occurrence_count'] = $combinedCount;
                            } else {
                                $existing->forceFill([
                                    'data' => [...$existingData, 'occurrence_count' => $combinedCount],
                                ])->save();
                                $payload['archive_reason'] = 'superseded';
                                $current->forceFill(['archived_at' => now()]);
                            }
                            $collapsed++;
                        }
                    }

                    $current->timestamps = false;
                    $current->forceFill([
                        'type' => $type,
                        'group_key' => $groupKey,
                        'data' => $payload,
                    ]);

                    if ($current->isDirty()) {
                        $current->save();
                        $updated++;
                    }
                });
            }
        }, 'id');

        $this->table(
            ['Mode', 'Processed', 'Updated', 'Collapsed', 'Skipped'],
            [[
                $dryRun ? 'dry-run' : 'write',
                $processed,
                $updated,
                $collapsed,
                $skipped,
            ]],
        );

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $data */
    private function stableType(string $storedType, array $data): ?string
    {
        $payloadType = $data['type'] ?? null;
        if (is_string($payloadType) && NotificationCatalogue::definition($payloadType) !== null) {
            return $payloadType;
        }

        if (NotificationCatalogue::definition($storedType) !== null) {
            return $storedType;
        }

        return [
            IntegrationCompleted::class => 'integration_completed',
            IntegrationFailed::class => 'integration_failed',
            IntegrationAuthenticationFailed::class => 'integration_authentication_failed',
            MigrationCompleted::class => 'migration_completed',
            MigrationFailed::class => 'migration_failed',
            DailyDigestReady::class => 'daily_digest',
            CookiesAutoRefreshed::class => 'cookie_auto_refreshed',
        ][$storedType] ?? null;
    }

    /** @param array<string, mixed> $data */
    private function groupKey(string $type, array $data): ?string
    {
        if (filled($data['group_key'] ?? null)) {
            return (string) $data['group_key'];
        }

        if ($type === 'daily_digest') {
            return 'daily_digest:' . ($data['period'] ?? 'daily');
        }

        if ($type === 'cookie_auto_refreshed') {
            $domain = $data['domain'] ?? data_get($data, 'data.domain');

            return is_string($domain) && $domain !== ''
                ? "cookie_auto_refreshed:{$domain}"
                : null;
        }

        $entityId = $data['entity_id'] ?? data_get($data, 'entity.id');
        if (is_string($entityId) && $entityId !== '') {
            return "{$type}:{$entityId}";
        }

        if (in_array($type, [
            'integration_failed',
            'integration_authentication_failed',
            'migration_failed',
        ], true)) {
            $actionUrl = $data['action_url'] ?? null;
            if (is_string($actionUrl)
                && preg_match('~/integrations/([0-9a-fA-F-]{36})(?:$|[/?#])~', $actionUrl, $matches) === 1) {
                return "{$type}:{$matches[1]}";
            }
        }

        return null;
    }
}
