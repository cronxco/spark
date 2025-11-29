<?php

namespace App\Integrations\Example\Jobs;

use App\Jobs\TaskPipeline\BaseTaskJob;

/**
 * Example task: Enrich event data with additional context
 *
 * This demonstrates how a plugin can implement custom task logic
 * that extends the base task pipeline functionality.
 */
class EnrichDataTask extends BaseTaskJob
{
    /**
     * Execute the data enrichment task
     */
    protected function execute(): void
    {
        // Example implementation - in a real plugin, this would:
        // 1. Call external API to fetch enrichment data
        // 2. Process and validate the response
        // 3. Update the model with enriched data

        // TODO: Implement actual enrichment logic
        // Example:
        // $client = new ExampleApiClient();
        // $enrichedData = $client->enrichEvent($this->model);
        //
        // $this->model->withoutEvents(function() use ($enrichedData) {
        //     $this->model->update([
        //         'metadata' => array_merge(
        //             $this->model->metadata ?? [],
        //             ['enriched_data' => $enrichedData]
        //         ),
        //     ]);
        // });
    }
}
