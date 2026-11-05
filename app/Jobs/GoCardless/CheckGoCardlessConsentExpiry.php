<?php

namespace App\Jobs\GoCardless;

use App\Integrations\GoCardless\GoCardlessBankPlugin;
use App\Models\IntegrationGroup;
use App\Notifications\GoCardlessConsentExpiring;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Warns before a bank's end-user agreement lapses, so the user can reconnect
 * before transactions stop syncing rather than finding out afterwards.
 */
class CheckGoCardlessConsentExpiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Days before expiry at which a warning is sent, largest first. */
    public const THRESHOLDS = [7, 1];

    public $tries = 1;

    public $timeout = 300;

    public function handle(): void
    {
        $groups = IntegrationGroup::where('service', 'gocardless')
            ->whereNotNull('auth_metadata')
            ->with(['user', 'integrations'])
            ->get();

        $plugin = null;
        $sent = 0;

        foreach ($groups as $group) {
            $metadata = $group->auth_metadata ?? [];
            $agreementId = $metadata['gocardless_agreement_id'] ?? null;
            $integration = $group->integrations->first(fn ($integration) => ! $integration->isPaused());

            if (! $group->user || ! $agreementId || ($metadata['eua_expired'] ?? false) || ! $integration) {
                continue;
            }

            try {
                // The agreement's expiry never changes, so look it up once per agreement
                if (($metadata['eua_expires_for'] ?? null) !== $agreementId || empty($metadata['eua_expires_at'])) {
                    $plugin ??= app(GoCardlessBankPlugin::class);
                    $expiresAt = $plugin->getAgreementExpiry($agreementId);
                    if (! $expiresAt) {
                        continue;
                    }
                    $metadata['eua_expires_for'] = $agreementId;
                    $metadata['eua_expires_at'] = $expiresAt->toIso8601String();
                    $metadata['eua_notifications_sent'] = [];
                    $this->saveMetadata($group, $metadata);
                }

                $expiresAt = Carbon::parse($metadata['eua_expires_at']);
                $daysLeft = (int) floor(now()->diffInDays($expiresAt, false));
                if ($daysLeft < 0) {
                    continue;
                }

                $threshold = collect(self::THRESHOLDS)->last(fn (int $days) => $daysLeft <= $days);
                if ($threshold === null || in_array($threshold, $metadata['eua_notifications_sent'] ?? [], true)) {
                    continue;
                }

                $group->user->notify(new GoCardlessConsentExpiring($group, $integration, $expiresAt, $daysLeft));
                $metadata['eua_notifications_sent'] = [...($metadata['eua_notifications_sent'] ?? []), $threshold];
                $this->saveMetadata($group, $metadata);
                $sent++;
            } catch (Throwable $exception) {
                Log::warning('CheckGoCardlessConsentExpiry: Could not check agreement', [
                    'group_id' => $group->id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        Log::info('CheckGoCardlessConsentExpiry: Completed', [
            'groups_checked' => $groups->count(),
            'notifications_sent' => $sent,
        ]);
    }

    /**
     * Merge only the keys this job owns, so concurrent auth updates are kept.
     */
    private function saveMetadata(IntegrationGroup $group, array $metadata): void
    {
        $current = $group->fresh()->auth_metadata ?? [];
        foreach (['eua_expires_for', 'eua_expires_at', 'eua_notifications_sent'] as $key) {
            $current[$key] = $metadata[$key] ?? null;
        }
        $group->update(['auth_metadata' => $current]);
    }
}
