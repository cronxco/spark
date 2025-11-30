<?php

namespace App\Jobs\TaskPipeline\Tasks;

use App\Jobs\TaskPipeline\BaseTaskJob;

class DetectTrendsTask extends BaseTaskJob
{
    /**
     * Execute the trend detection task
     *
     * Note: This task is scheduled-only and runs via DispatchTrendDetectionTasksJob
     * It is not triggered on event creation
     */
    protected function execute(): void
    {
        // Trend detection is a scheduled batch job that analyzes all metrics
        // The actual implementation is in DetectMetricTrendsJob which processes
        // all metric statistics globally.
        //
        // This task skeleton exists for consistency but the work is done
        // by the scheduled job, not per-event.

        // If we wanted per-event trend detection, we could implement it here,
        // but for now the scheduled batch approach is more efficient.
    }
}
