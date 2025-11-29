<?php

namespace App\Jobs\TaskPipeline;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DispatchTrendDetectionTasksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 900; // 15 minutes
    public $tries = 2;
    public $backoff = [300, 600]; // 5m, 10m

    /**
     * Execute the job - dispatch trend detection for events with metrics
     */
    public function handle(): void
    {
        Log::info('Starting scheduled trend detection');

        $count = 0;

        // Get all unique metric combinations (service, action, value_unit)
        // from events in the last week
        Event::query()
            ->select('service', 'action', 'value_unit')
            ->whereNotNull('value')
            ->whereNotNull('value_unit')
            ->where('time', '>=', now()->subWeek())
            ->groupBy('service', 'action', 'value_unit')
            ->chunk(100, function($metricGroups) use (&$count) {
                foreach ($metricGroups as $group) {
                    // Get the most recent event for this metric to trigger trend detection
                    $event = Event::query()
                        ->where('service', $group->service)
                        ->where('action', $group->action)
                        ->where('value_unit', $group->value_unit)
                        ->orderBy('time', 'desc')
                        ->first();

                    if ($event) {
                        // Dispatch task pipeline with just the trend detection task
                        ProcessTaskPipelineJob::dispatch(
                            model: $event,
                            trigger: 'scheduled',
                            taskFilter: ['detect_trends'],
                            force: true, // Recalculate trends
                        )->onQueue('tasks');

                        $count++;
                    }
                }
            });

        Log::info("Dispatched trend detection for {$count} metric groups");
    }
}
