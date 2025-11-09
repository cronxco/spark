<?php

namespace App\Jobs\Fetch;

use App\Integrations\Fetch\PlaywrightFetchClient;
use App\Models\EventObject;
use App\Models\IntegrationGroup;
use App\Notifications\CookiesAutoRefreshed;
use Carbon\Carbon;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshExpiringCookies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function handle(): void
    {
        $thresholdDays = config('services.playwright.cookie_refresh_threshold_days', 7);

        Log::info('Fetch: Starting cookie auto-refresh job', [
            'threshold_days' => $thresholdDays,
        ]);

        // Find all Fetch integration groups
        $groups = IntegrationGroup::where('service', 'fetch')
            ->with('user')
            ->get();

        $refreshedCount = 0;

        foreach ($groups as $group) {
            $authMetadata = $group->auth_metadata ?? [];
            $domains = $authMetadata['domains'] ?? [];

            foreach ($domains as $domain => $config) {
                // Skip if auto-refresh is not enabled for this domain
                if (! ($config['auto_refresh_enabled'] ?? false)) {
                    continue;
                }

                // Check if cookies are expiring soon
                $expiresAt = $config['expires_at'] ?? null;
                if (! $expiresAt) {
                    Log::debug('Fetch: Domain has no expiry set, skipping', [
                        'domain' => $domain,
                    ]);

                    continue;
                }

                $expiryDate = Carbon::parse($expiresAt);
                $now = now();
                $daysUntilExpiry = $now->diffInDays($expiryDate, false);

                // Skip if not expiring within threshold
                if ($daysUntilExpiry > $thresholdDays || $daysUntilExpiry < 0) {
                    Log::debug('Fetch: Domain not expiring within threshold', [
                        'domain' => $domain,
                        'days_until_expiry' => $daysUntilExpiry,
                        'threshold' => $thresholdDays,
                    ]);

                    continue;
                }

                Log::info('Fetch: Refreshing cookies for domain', [
                    'domain' => $domain,
                    'days_until_expiry' => $daysUntilExpiry,
                    'user_id' => $group->user_id,
                ]);

                // Find an active webpage for this domain
                $webpage = EventObject::where('user_id', $group->user_id)
                    ->where('concept', 'bookmark')
                    ->where('type', 'fetch_webpage')
                    ->whereRaw("metadata->>'domain' = ?", [$domain])
                    ->whereRaw("(metadata->>'enabled')::boolean = true")
                    ->first();

                if (! $webpage) {
                    Log::warning('Fetch: No active webpage found for domain', [
                        'domain' => $domain,
                        'user_id' => $group->user_id,
                    ]);

                    continue;
                }

                // Attempt to refresh cookies using Playwright
                try {
                    $client = new PlaywrightFetchClient;
                    $result = $client->fetch($webpage->url, $group);

                    if ($result['success']) {
                        // Cookies are automatically updated by PlaywrightFetchClient
                        // Update last_refreshed_at timestamp
                        $authMetadata = $group->fresh()->auth_metadata ?? [];
                        $domains = $authMetadata['domains'] ?? [];

                        if (isset($domains[$domain])) {
                            $domains[$domain]['last_refreshed_at'] = now()->toIso8601String();

                            // Calculate next refresh date (7 days before expiry)
                            if (isset($domains[$domain]['expires_at'])) {
                                $newExpiryDate = Carbon::parse($domains[$domain]['expires_at']);
                                $nextRefresh = $newExpiryDate->subDays($thresholdDays);
                                $domains[$domain]['next_refresh_at'] = $nextRefresh->toIso8601String();
                            }

                            $authMetadata['domains'] = $domains;
                            $group->update(['auth_metadata' => $authMetadata]);
                        }

                        // Send notification to user
                        $group->user->notify(
                            new CookiesAutoRefreshed(
                                $domain,
                                count($result['cookies'] ?? []),
                                $expiryDate
                            )
                        );

                        $refreshedCount++;

                        Log::info('Fetch: Successfully refreshed cookies', [
                            'domain' => $domain,
                            'cookie_count' => count($result['cookies'] ?? []),
                            'user_id' => $group->user_id,
                        ]);
                    } else {
                        Log::warning('Fetch: Failed to refresh cookies', [
                            'domain' => $domain,
                            'error' => $result['error'] ?? 'Unknown error',
                        ]);
                    }
                } catch (Exception $e) {
                    Log::error('Fetch: Exception during cookie refresh', [
                        'domain' => $domain,
                        'url' => $webpage->url,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        Log::info('Fetch: Cookie auto-refresh job completed', [
            'refreshed_count' => $refreshedCount,
        ]);
    }
}
