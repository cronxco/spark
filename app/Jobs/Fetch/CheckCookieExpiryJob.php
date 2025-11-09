<?php

namespace App\Jobs\Fetch;

use App\Models\IntegrationGroup;
use App\Notifications\CookieExpiryWarning;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckCookieExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300; // 5 minutes

    public function handle(): void
    {
        Log::info('CheckCookieExpiryJob: Starting cookie expiry check');

        // Get all Fetch integration groups with cookies
        $groups = IntegrationGroup::where('service', 'fetch')
            ->whereNotNull('auth_metadata')
            ->with('user')
            ->get();

        if ($groups->isEmpty()) {
            Log::info('CheckCookieExpiryJob: No Fetch integration groups found');

            return;
        }

        $now = Carbon::now();
        $notificationsSent = 0;

        foreach ($groups as $group) {
            if (! $group->user) {
                continue;
            }

            $authMetadata = $group->auth_metadata;
            if (! isset($authMetadata['domains']) || ! is_array($authMetadata['domains'])) {
                continue;
            }

            // Initialize notification tracking if not present
            if (! isset($authMetadata['cookie_notifications_sent'])) {
                $authMetadata['cookie_notifications_sent'] = [];
            }

            $updatedMetadata = false;

            foreach ($authMetadata['domains'] as $domain => $domainConfig) {
                // Skip domains without expiry dates
                if (! isset($domainConfig['expires_at'])) {
                    continue;
                }

                try {
                    $expiresAt = Carbon::parse($domainConfig['expires_at']);
                } catch (Exception $e) {
                    Log::warning('CheckCookieExpiryJob: Invalid expiry date', [
                        'domain' => $domain,
                        'expires_at' => $domainConfig['expires_at'],
                    ]);

                    continue;
                }

                $daysUntilExpiry = $now->diffInDays($expiresAt, false);

                // Skip if already expired or far in the future
                if ($daysUntilExpiry < 0 || $daysUntilExpiry > 7) {
                    continue;
                }

                // Determine notification threshold
                $threshold = null;
                if ($daysUntilExpiry <= 1) {
                    $threshold = '1day';
                } elseif ($daysUntilExpiry <= 3) {
                    $threshold = '3day';
                } elseif ($daysUntilExpiry <= 7) {
                    $threshold = '7day';
                }

                if (! $threshold) {
                    continue;
                }

                // Initialize tracking for this domain if needed
                if (! isset($authMetadata['cookie_notifications_sent'][$domain])) {
                    $authMetadata['cookie_notifications_sent'][$domain] = [];
                }

                // Check if we've already sent this threshold notification today
                $lastSent = $authMetadata['cookie_notifications_sent'][$domain][$threshold] ?? null;
                if ($lastSent && Carbon::parse($lastSent)->isToday()) {
                    Log::debug('CheckCookieExpiryJob: Already sent notification today', [
                        'domain' => $domain,
                        'threshold' => $threshold,
                    ]);

                    continue;
                }

                // Send notification
                $group->user->notify(
                    new CookieExpiryWarning($domain, $expiresAt, $daysUntilExpiry)
                );

                // Record that we sent this notification
                $authMetadata['cookie_notifications_sent'][$domain][$threshold] = $now->toIso8601String();
                $updatedMetadata = true;
                $notificationsSent++;

                Log::info('CheckCookieExpiryJob: Sent cookie expiry notification', [
                    'domain' => $domain,
                    'threshold' => $threshold,
                    'days_until_expiry' => $daysUntilExpiry,
                    'expires_at' => $expiresAt->toIso8601String(),
                    'user_id' => $group->user_id,
                ]);
            }

            // Update group metadata if we sent any notifications
            if ($updatedMetadata) {
                $group->update(['auth_metadata' => $authMetadata]);
            }
        }

        Log::info('CheckCookieExpiryJob: Completed', [
            'groups_checked' => $groups->count(),
            'notifications_sent' => $notificationsSent,
        ]);
    }
}
