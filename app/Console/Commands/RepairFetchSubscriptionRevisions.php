<?php

namespace App\Console\Commands;

use App\Jobs\Fetch\FetchSingleUrl;
use App\Models\Event;
use App\Models\EventObject;
use App\Models\Integration;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairFetchSubscriptionRevisions extends Command
{
    protected $signature = 'fetch:repair-subscription-revisions
                            {--object= : Repair one Fetch webpage object UUID}
                            {--dry-run : Report affected subscriptions without dispatching fetches}
                            {--force : Requeue a repair already marked as queued}';

    protected $description = 'Create a current immutable revision for legacy recurring Fetch subscriptions';

    public function handle(): int
    {
        $query = EventObject::query()
            ->where('concept', 'bookmark')
            ->where('type', 'fetch_webpage')
            ->where(function ($query): void {
                $query->where('metadata->fetch_mode', 'recurring')
                    ->orWhereNull('metadata->fetch_mode');
            })
            ->where(function ($query): void {
                $query->where('metadata->enabled', true)
                    ->orWhereNull('metadata->enabled');
            });

        if ($objectId = $this->option('object')) {
            $query->whereKey($objectId);
        }

        $rows = [];
        $queued = 0;
        $skipped = 0;

        foreach ($query->cursor() as $webpage) {
            $metadata = $webpage->metadata ?? [];
            $latestEvent = $this->latestEvent($webpage, $metadata['latest_event_id'] ?? null);
            $alreadyVersioned = ($latestEvent?->event_metadata['revision_model_version'] ?? null) === 1;
            $alreadyQueued = ! $this->option('force')
                && array_key_exists('revision_repair_queued_for_hash', $metadata)
                && ($metadata['revision_repair_queued_for_hash'] ?? null) === ($metadata['content_hash'] ?? null);

            $status = $alreadyVersioned ? 'versioned' : ($alreadyQueued ? 'queued' : 'repair');
            $rows[] = [
                $webpage->id,
                $webpage->title,
                $latestEvent?->id ?? 'none',
                $webpage->tags()->count(),
                $status,
            ];

            if ($alreadyVersioned || $alreadyQueued || $this->option('dry-run')) {
                $skipped++;

                continue;
            }

            $integrationId = $metadata['fetch_integration_id'] ?? $metadata['integration_id'] ?? null;
            $integration = $integrationId ? Integration::find($integrationId) : null;

            if (! $integration || ! $webpage->url) {
                $rows[array_key_last($rows)][4] = 'missing integration/url';
                $skipped++;

                continue;
            }

            $claimStatus = $this->claimRepair($webpage);
            if ($claimStatus !== 'claimed') {
                $rows[array_key_last($rows)][4] = $claimStatus;
                $skipped++;

                continue;
            }

            FetchSingleUrl::dispatch($integration, $webpage->id, $webpage->url, true);
            $queued++;
        }

        $this->table(['Object', 'Title', 'Latest event', 'Tags', 'Status'], $rows);
        $this->info($this->option('dry-run')
            ? "Dry run complete: {$skipped} subscription(s) inspected."
            : "Repair dispatch complete: {$queued} queued, {$skipped} skipped.");

        return Command::SUCCESS;
    }

    private function latestEvent(EventObject $webpage, ?string $latestEventId): ?Event
    {
        $event = $latestEventId
            ? Event::query()
                ->whereKey($latestEventId)
                ->where('target_id', $webpage->id)
                ->where('service', 'fetch')
                ->first()
            : null;

        if ($event) {
            return $event;
        }

        return Event::query()
            ->where('target_id', $webpage->id)
            ->where('service', 'fetch')
            ->latest('time')
            ->first();
    }

    private function claimRepair(EventObject $webpage): string
    {
        return DB::transaction(function () use ($webpage): string {
            $lockedWebpage = EventObject::query()->lockForUpdate()->findOrFail($webpage->id);
            $metadata = $lockedWebpage->metadata ?? [];
            $latestEvent = $this->latestEvent($lockedWebpage, $metadata['latest_event_id'] ?? null);

            if (($latestEvent?->event_metadata['revision_model_version'] ?? null) === 1) {
                return 'versioned';
            }

            $claimedHash = $metadata['revision_repair_queued_for_hash'] ?? null;
            $currentHash = $metadata['content_hash'] ?? null;
            $claimedAt = isset($metadata['revision_repair_queued_at'])
                ? CarbonImmutable::parse($metadata['revision_repair_queued_at'])
                : null;
            $claimIsActive = $claimedHash === $currentHash
                && $claimedAt?->isAfter(now()->subMinutes(15));

            if ($claimedHash === $currentHash && (! $this->option('force') || $claimIsActive)) {
                return 'queued';
            }

            $metadata['revision_repair_queued_for_hash'] = $currentHash;
            $metadata['revision_repair_queued_at'] = now()->toIso8601String();
            $lockedWebpage->update(['metadata' => $metadata]);

            return 'claimed';
        }, 3);
    }
}
