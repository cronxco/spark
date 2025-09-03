<?php

namespace App\Jobs;

use App\Integrations\PluginRegistry;
use App\Jobs\OAuth\GitHub\GitHubActivityPull;
use App\Jobs\OAuth\GoCardless\GoCardlessAccountPull;
use App\Jobs\OAuth\GoCardless\GoCardlessBalancePull;
use App\Jobs\OAuth\GoCardless\GoCardlessTransactionPull;
use App\Jobs\OAuth\Hevy\HevyWorkoutPull;
use App\Jobs\OAuth\Monzo\MonzoAccountPull;
use App\Jobs\OAuth\Monzo\MonzoBalancePull;
use App\Jobs\OAuth\Monzo\MonzoPotPull;
use App\Jobs\OAuth\Monzo\MonzoTransactionPull;
use App\Jobs\OAuth\Oura\OuraActivityPull;
use App\Jobs\OAuth\Oura\OuraHeartratePull;
use App\Jobs\OAuth\Oura\OuraReadinessPull;
use App\Jobs\OAuth\Oura\OuraResiliencePull;
use App\Jobs\OAuth\Oura\OuraSessionsPull;
use App\Jobs\OAuth\Oura\OuraSleepPull;
use App\Jobs\OAuth\Oura\OuraSleepRecordsPull;
use App\Jobs\OAuth\Oura\OuraSpo2Pull;
use App\Jobs\OAuth\Oura\OuraStressPull;
use App\Jobs\OAuth\Oura\OuraTagsPull;
use App\Jobs\OAuth\Oura\OuraWorkoutsPull;
use App\Jobs\OAuth\Spotify\SpotifyListeningPull;
use App\Models\Integration;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\Severity;
use Sentry\Tracing\SpanStatus;
use Sentry\Tracing\TransactionContext;
use Throwable;

class CheckIntegrationUpdates implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60; // 1 minute

    public $tries = 1; // Don't retry this job

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hub = SentrySdk::getCurrentHub();
        $txContext = new TransactionContext;
        $txContext->setName('job.check_integration_updates');
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            Log::info('Starting integration update check');

            // Get integrations that need updating
            // - OAuth: require a valid group token
            // - API key: no token requirement
            $oauthServices = PluginRegistry::getOAuthPlugins()->keys();
            $apiKeyServices = PluginRegistry::getApiKeyPlugins()->keys();

            // Get all integrations that could potentially need updating
            $allIntegrations = Integration::with(['user', 'group'])
                ->whereHas('user')
                ->where(function ($query) use ($oauthServices, $apiKeyServices) {
                    $query->where(function ($q) use ($oauthServices) {
                        // OAuth integrations with a token
                        $q->whereIn('service', $oauthServices)
                            ->whereHas('group', function ($groupQuery) {
                                $groupQuery->whereNotNull('access_token');
                            });
                    })->orWhere(function ($q) use ($apiKeyServices) {
                        // API key integrations (no token required)
                        $q->whereIn('service', $apiKeyServices);
                    });
                })
                ->get();

            // Filter to only those that actually need updating using the individual method
            $integrations = $allIntegrations->filter(function ($integration) {
                return $integration->needsUpdate();
            });

            if ($integrations->isEmpty()) {
                Log::info('No integrations found that need updating');

                return;
            }

            Log::info("Found {$integrations->count()} integration(s) that need updating");

            $scheduledCount = 0;
            $skippedCount = 0;

            foreach ($integrations as $integration) {
                try {
                    // Skip if currently processing
                    if ($integration->isProcessing()) {
                        Log::info("Skipping integration {$integration->id} ({$integration->service}) - currently processing");
                        $skippedCount++;

                        continue;
                    }

                    // Check if it's time to fetch data based on update frequency
                    if ($integration->last_triggered_at &&
                $integration->last_triggered_at->addMinutes($integration->getUpdateFrequencyMinutes())->isFuture()) {
                        Log::info("Skipping integration {$integration->id} ({$integration->service}) - too soon since last update");
                        $skippedCount++;

                        continue;
                    }

                    // Dispatch appropriate fetch jobs based on integration service and instance type
                    $fetchJobs = $this->getFetchJobsForIntegration($integration);
                    foreach ($fetchJobs as $jobClass) {
                        $jobClass::dispatch($integration);
                    }

                    // Mark the integration as triggered to prevent immediate re-triggering
                    $integration->markAsTriggered();

                    Log::info("Scheduled fetch jobs for integration {$integration->id} ({$integration->service}) - User: {$integration->user->name}");
                    $scheduledCount++;

                } catch (Exception $e) {
                    Log::error("Failed to schedule job for integration {$integration->id} ({$integration->service})", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    \Sentry\captureException($e);
                }
            }

            \Sentry\captureMessage('CheckIntegrationUpdates summary', Severity::info(), EventHint::fromArray(['extra' => [
                'scheduled' => $scheduledCount,
                'skipped' => $skippedCount,
                'total_due' => $integrations->count(),
            ]]));

            Log::info("Integration update check completed: {$scheduledCount} scheduled, {$skippedCount} skipped");
            $transaction->setStatus(SpanStatus::ok());

        } catch (Exception $e) {
            Log::error('Failed to check integration updates', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            \Sentry\captureException($e);
            $transaction->setStatus(SpanStatus::internalError());

            throw $e;
        } finally {
            $transaction->finish();
            $hub->setSpan(null);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Integration update check job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
        \Sentry\captureException($exception);
    }

    /**
     * Get the appropriate fetch jobs for an integration based on its service and instance type.
     */
    private function getFetchJobsForIntegration(Integration $integration): array
    {
        return match ($integration->service) {
            'monzo' => $this->getMonzoFetchJobs($integration),
            'gocardless' => $this->getGoCardlessFetchJobs($integration),
            'github' => $this->getGitHubFetchJobs($integration),
            'spotify' => $this->getSpotifyFetchJobs($integration),
            'oura' => $this->getOuraFetchJobs($integration),
            'hevy' => $this->getHevyFetchJobs($integration),
            // Add other services here as they are implemented
            default => [],
        };
    }

    /**
     * Get Monzo-specific fetch jobs based on instance type.
     */
    private function getMonzoFetchJobs(Integration $integration): array
    {
        $instanceType = $integration->instance_type ?: 'transactions';

        return match ($instanceType) {
            'accounts' => [MonzoAccountPull::class],
            'transactions' => [MonzoTransactionPull::class],
            'pots' => [MonzoPotPull::class],
            'balances' => [MonzoBalancePull::class],
            default => [],
        };
    }

    /**
     * Get Oura-specific fetch jobs based on instance type.
     */
    private function getGoCardlessFetchJobs(Integration $integration): array
    {
        $instanceType = $integration->instance_type ?: 'transactions';

        return match ($instanceType) {
            'accounts' => [GoCardlessAccountPull::class],
            'transactions' => [GoCardlessTransactionPull::class],
            'balances' => [GoCardlessBalancePull::class],
            default => [],
        };
    }

    private function getGitHubFetchJobs(Integration $integration): array
    {
        $instanceType = $integration->instance_type ?: 'activity';

        return match ($instanceType) {
            'activity' => [GitHubActivityPull::class],
            default => [],
        };
    }

    private function getSpotifyFetchJobs(Integration $integration): array
    {
        $instanceType = $integration->instance_type ?: 'listening';

        return match ($instanceType) {
            'listening' => [SpotifyListeningPull::class],
            default => [],
        };
    }

    private function getOuraFetchJobs(Integration $integration): array
    {
        $instanceType = $integration->instance_type ?: 'activity';

        return match ($instanceType) {
            'activity' => [OuraActivityPull::class],
            'sleep' => [OuraSleepPull::class],
            'sleep_records' => [OuraSleepRecordsPull::class],
            'readiness' => [OuraReadinessPull::class],
            'resilience' => [OuraResiliencePull::class],
            'stress' => [OuraStressPull::class],
            'workouts' => [OuraWorkoutsPull::class],
            'sessions' => [OuraSessionsPull::class],
            'tags' => [OuraTagsPull::class],
            'heartrate' => [OuraHeartratePull::class],
            'spo2' => [OuraSpo2Pull::class],
            default => [],
        };
    }

    private function getHevyFetchJobs(Integration $integration): array
    {
        $instanceType = $integration->instance_type ?: 'workouts';

        return match ($instanceType) {
            'workouts' => [HevyWorkoutPull::class],
            default => [],
        };
    }
}
