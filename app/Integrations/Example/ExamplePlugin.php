<?php

namespace App\Integrations\Example;

use App\Integrations\Contracts\SupportsTaskPipeline;
use App\Services\TaskPipeline\TaskDefinition;

/**
 * Example plugin demonstrating task pipeline integration
 *
 * This is a demonstration of how integration plugins can register
 * custom tasks into the task pipeline system.
 */
class ExamplePlugin implements SupportsTaskPipeline
{
    /**
     * Get task definitions for this plugin
     *
     * @return array<TaskDefinition>
     */
    public static function getTaskDefinitions(): array
    {
        return [
            // Example: Custom data enrichment task
            new TaskDefinition(
                key: 'example_enrich_data',
                name: 'Enrich Example Data',
                description: 'Enriches event data with additional context from Example service',
                jobClass: \App\Integrations\Example\Jobs\EnrichDataTask::class,
                appliesTo: ['event'],
                conditions: [
                    'service' => 'example',
                ],
                dependencies: ['generate_embedding'], // Run after embeddings
                queue: 'tasks',
                priority: 45,
                runOnCreate: true,
                runOnUpdate: false,
            ),

            // Example: Custom summary generation task
            new TaskDefinition(
                key: 'example_generate_summary',
                name: 'Generate Example Summary',
                description: 'Generates AI summary for Example objects',
                jobClass: \App\Integrations\Example\Jobs\GenerateSummaryTask::class,
                appliesTo: ['object'],
                conditions: [
                    'service' => 'example',
                    'concept' => 'activity',
                ],
                dependencies: ['generate_embedding'],
                queue: 'tasks',
                priority: 40,
                runOnCreate: false,
                runOnUpdate: true,
                shouldRun: function($model) {
                    // Only run if model has sufficient data
                    return !empty($model->content) && strlen($model->content) > 100;
                },
            ),
        ];
    }
}
