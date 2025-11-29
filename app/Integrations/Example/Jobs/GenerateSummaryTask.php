<?php

namespace App\Integrations\Example\Jobs;

use App\Jobs\TaskPipeline\BaseTaskJob;

/**
 * Example task: Generate AI summary for objects
 *
 * This demonstrates how a plugin can implement tasks that operate
 * on different model types with conditional execution logic.
 */
class GenerateSummaryTask extends BaseTaskJob
{
    /**
     * Execute the summary generation task
     */
    protected function execute(): void
    {
        // Example implementation - in a real plugin, this would:
        // 1. Analyze the object's content
        // 2. Generate a summary using AI or other methods
        // 3. Store the summary back to the model

        // TODO: Implement actual summary generation logic
        // Example:
        // $summaryService = app(SummaryService::class);
        // $summary = $summaryService->generateSummary($this->model->content);
        //
        // $this->model->withoutEvents(function() use ($summary) {
        //     $metadata = $this->model->metadata ?? [];
        //     $metadata['ai_summary'] = $summary;
        //     $this->model->update(['metadata' => $metadata]);
        // });
    }
}
