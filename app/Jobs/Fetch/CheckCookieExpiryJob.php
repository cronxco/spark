<?php

namespace App\Jobs\Fetch;

use App\Models\IntegrationGroup;
use App\Notifications\CookieExpiryWarning;
use App\Services\TaskPipeline\TaskDefinition;
use App\Services\TaskPipeline\TaskExecutionStore;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckCookieExpiryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300; // 5 minutes

    public function handle(TaskExecutionStore $store): void
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
                    $store->recordStatus($group, $this->taskDefinition($domain), 'failed', [
                        'domain' => $domain,
                        'error' => 'Invalid expiry date: ' . $e->getMessage(),
                    ]);

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

                $task = $this->taskDefinition($domain);

                $store->recordStatus($group, $task, 'pending', [
                    'domain' => $domain,
                    'threshold' => $threshold,
                    'days_until_expiry' => $daysUntilExpiry,
                ]);

                // Initialize tracking for this domain if needed
                if (! isset($authMetadata['cookie_notifications_sent'][$domain])) {
                    $authMetadata['cookie_notifications_sent'][$domain] = [];
                }

                // Check if we've already sent this threshold notification today
                $lastSent = $authMetadata['cookie_notifications_sent'][$domain][$threshold] ?? null;
                if ($lastSent && Carbon::parse($lastSent)->isToday()) {
                    $store->recordStatus($group, $task, 'success', [
                        'domain' => $domain,
                        'threshold' => $threshold,
                        'days_until_expiry' => $daysUntilExpiry,
                        'notification_sent' => false,
                    ]);

                    Log::debug('CheckCookieExpiryJob: Already sent notification today', [
                        'domain' => $domain,
                        'threshold' => $threshold,
                    ]);

                    continue;
                }

                // Send notification
                try {
                    $group->user->notify(
                        new CookieExpiryWarning($group, $domain, $expiresAt->toIso8601String(), $daysUntilExpiry)
                    );
                } catch (Throwable $exception) {
                    $store->recordStatus($group, $task, 'failed', [
                        'domain' => $domain,
                        'threshold' => $threshold,
                        'days_until_expiry' => $daysUntilExpiry,
                        'error' => $exception->getMessage(),
                    ]);

                    throw $exception;
                }

                // Record that we sent this notification
                $authMetadata['cookie_notifications_sent'][$domain][$threshold] = $now->toIso8601String();
                $updatedMetadata = true;
                $notificationsSent++;

                $store->recordStatus($group, $task, 'success', [
                    'domain' => $domain,
                    'threshold' => $threshold,
                    'days_until_expiry' => $daysUntilExpiry,
                    'notification_sent' => true,
                ]);

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
                $currentAuthMetadata = $group->fresh()->auth_metadata ?? [];
                $currentAuthMetadata['cookie_notifications_sent'] = $authMetadata['cookie_notifications_sent'];
                $group->update(['auth_metadata' => $currentAuthMetadata]);
            }
        }

        Log::info('CheckCookieExpiryJob: Completed', [
            'groups_checked' => $groups->count(),
            'notifications_sent' => $notificationsSent,
        ]);
    }

    private function taskDefinition(string $domain): TaskDefinition
    {
        return new TaskDefinition(
            key: 'check_cookie_expiry_' . str_replace('.', '_', $domain),
            name: "Check Cookie Expiry: {$domain}",
            description: 'Warns the user before fetch cookies for a domain expire.',
            jobClass: self::class,
            appliesTo: ['integration_group'],
            queue: 'fetch',
        );
    }
}
