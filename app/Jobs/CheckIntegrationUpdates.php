<?php

namespace App\Jobs;

use App\Jobs\ProcessIntegrationData;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Sentry\SentrySdk;
use Sentry\Tracing\TransactionContext;

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
        $txContext = new TransactionContext();
        $txContext->setName('job.check_integration_updates');
        $txContext->setOp('job');
        $transaction = $hub->startTransaction($txContext);
        $hub->setSpan($transaction);

        try {
            Log::info('Starting integration update check');
            
            // Get integrations that need updating
            // - OAuth: require a valid group token
            // - API key: no token requirement
            $oauthServices = \App\Integrations\PluginRegistry::getOAuthPlugins()->keys();
            $apiKeyServices = \App\Integrations\PluginRegistry::getApiKeyPlugins()->keys();

            $integrations = Integration::with(['user', 'group'])
                ->whereHas('user')
                ->where(function ($query) use ($oauthServices, $apiKeyServices) {
                    // OAuth integrations with a token
                    $query->whereIn('service', $oauthServices)
                        ->whereHas('group', function ($q) {
                            $q->whereNotNull('access_token');
                        });
                })
                ->orWhere(function ($query) use ($apiKeyServices) {
                    // API key integrations (no token required)
                    $query->whereIn('service', $apiKeyServices);
                })
                ->needsUpdate()
                ->get();
            
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
                        $integration->last_triggered_at->addMinutes($integration->update_frequency_minutes)->isFuture()) {
                        Log::info("Skipping integration {$integration->id} ({$integration->service}) - too soon since last update");
                        $skippedCount++;
                        continue;
                    }
                    
                    // Dispatch the processing job
                    ProcessIntegrationData::dispatch($integration);
                    
                    Log::info("Scheduled processing job for integration {$integration->id} ({$integration->service}) - User: {$integration->user->name}");
                    $scheduledCount++;
                    
                } catch (\Exception $e) {
                    Log::error("Failed to schedule job for integration {$integration->id} ({$integration->service})", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);
                    \Sentry\captureException($e);
                }
            }
            
            \Sentry\captureMessage('CheckIntegrationUpdates summary', \Sentry\Severity::info(), \Sentry\EventHint::fromArray(['extra' => [
                'scheduled' => $scheduledCount,
                'skipped' => $skippedCount,
                'total_due' => $integrations->count(),
            ]]));
            
            Log::info("Integration update check completed: {$scheduledCount} scheduled, {$skippedCount} skipped");
            $transaction->setStatus(\Sentry\Tracing\SpanStatus::ok());
            
        } catch (\Exception $e) {
            Log::error('Failed to check integration updates', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            \Sentry\captureException($e);
            $transaction->setStatus(\Sentry\Tracing\SpanStatus::internalError());
            
            throw $e;
        } finally {
            $transaction->finish();
            $hub->setSpan(null);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Integration update check job failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
        \Sentry\captureException($exception);
    }
}
