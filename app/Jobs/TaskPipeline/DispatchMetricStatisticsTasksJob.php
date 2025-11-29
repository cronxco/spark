<?php

namespace App\Jobs\TaskPipeline;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchMetricStatisticsTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600; // 10 minutes
    public $tries = 2;
    public $backoff = [120, 300]; // 2m, 5m

    /**
     * Execute the job - dispatch metric statistics calculation for recent events
     */
    public function handle(): void
    {
        Log::info('Starting scheduled metric statistics calculation');

        // Find all events with metrics from the last day
        // This ensures we recalculate stats as new data comes in
        $count = 0;

        Event::query()
            ->whereNotNull('value')
            ->whereNotNull('value_unit')
            ->where('time', '>=', now()->subDay())
            ->chunk(100, function ($events) use (&$count) {
                foreach ($events as $event) {
                    // Dispatch task pipeline with just the stats calculation task
                    ProcessTaskPipelineJob::dispatch(
                        model: $event,
                        trigger: 'scheduled',
                        taskFilter: ['calculate_metric_stats'],
                        force: true, // Recalculate even if previously done
                    )->onQueue('tasks');

                    $count++;
                }
            });

        Log::info("Dispatched metric statistics calculation for {$count} events");
    }
}
