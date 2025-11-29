<?php

namespace App\Jobs\TaskPipeline;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchRetrospectiveAnomalyTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // 15 minutes
    public $tries = 2;
    public $backoff = [300, 600]; // 5m, 10m

    /**
     * Execute the job - dispatch retrospective anomaly detection
     */
    public function handle(): void
    {
        Log::info('Starting scheduled retrospective anomaly detection');

        $count = 0;

        // Find all events with metrics from yesterday
        // This allows retrospective detection for:
        // 1. Integrations with anomaly_detection_mode='retrospective'
        // 2. Events that didn't get real-time anomaly detection
        Event::query()
            ->whereNotNull('value')
            ->whereNotNull('value_unit')
            ->whereBetween('time', [
                now()->subDay()->startOfDay(),
                now()->subDay()->endOfDay(),
            ])
            ->chunk(100, function($events) use (&$count) {
                foreach ($events as $event) {
                    // Check if anomaly detection has already been run successfully
                    $executions = $event->metadata['task_executions'] ?? [];
                    $lastAttempt = $executions['detect_anomalies']['last_attempt'] ?? null;

                    // Skip if already successfully detected
                    if ($lastAttempt && $lastAttempt['status'] === 'success') {
                        continue;
                    }

                    // Dispatch task pipeline with anomaly detection
                    // This will also run stats calculation if needed (due to dependency)
                    ProcessTaskPipelineJob::dispatch(
                        model: $event,
                        trigger: 'scheduled',
                        taskFilter: ['detect_anomalies'],
                        force: false, // Don't force if already done
                    )->onQueue('tasks');

                    $count++;
                }
            });

        Log::info("Dispatched retrospective anomaly detection for {$count} events");
    }
}
