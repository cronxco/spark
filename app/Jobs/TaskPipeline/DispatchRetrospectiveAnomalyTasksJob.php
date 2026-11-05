<?php

namespace App\Jobs\TaskPipeline;

use App\Jobs\TaskPipeline\Tasks\DetectAnomaliesTask;
use App\Models\Event;
use App\Services\TaskPipeline\TaskExecutionStore;
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
        $store = app(TaskExecutionStore::class);

        // Find all events with metrics from the last two days
        // This allows retrospective detection for:
        // 1. Integrations with anomaly_detection_mode='retrospective'
        // 2. Events that didn't get real-time anomaly detection
        // 3. Daily aggregates left unjudged while their day was still filling
        //    up. Two days back covers a day that ends after this run in the
        //    user's timezone. Today is swept too, for values that were
        //    deferred while their source was still revising them.
        Event::query()
            ->withoutInternal()
            ->whereNotNull('value')
            ->whereNotNull('value_unit')
            ->whereBetween('time', [
                now()->subDays(2)->startOfDay(),
                now()->endOfDay(),
            ])
            ->chunk(100, function ($events) use (&$count, $store) {
                foreach ($events as $event) {
                    // Check if anomaly detection has already been run successfully
                    $executions = $store->getTaskExecutions($event);
                    $lastAttempt = $executions['detect_anomalies']['last_attempt'] ?? null;
                    $succeeded = $lastAttempt && $lastAttempt['status'] === 'success';
                    $deferred = $succeeded
                        && ($lastAttempt['outcome'] ?? null) === DetectAnomaliesTask::OUTCOME_DAY_IN_PROGRESS;

                    // Skip if already successfully detected
                    if ($succeeded && ! $deferred) {
                        continue;
                    }

                    // Only deferred values are swept outside yesterday
                    $isYesterday = $event->time->between(now()->subDay()->startOfDay(), now()->subDay()->endOfDay());
                    if (! $deferred && ! $isYesterday) {
                        continue;
                    }

                    // Dispatch task pipeline with anomaly detection
                    // This will also run stats calculation if needed (due to dependency)
                    ProcessTaskPipelineJob::dispatch(
                        model: $event,
                        trigger: 'scheduled',
                        taskFilter: ['detect_anomalies'],
                        force: $deferred, // A deferred run counts as done, so it has to be forced
                    )->onQueue('tasks');

                    $count++;
                }
            });

        Log::info("Dispatched retrospective anomaly detection for {$count} events");
    }
}
