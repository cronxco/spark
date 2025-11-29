<?php

namespace App\Jobs\TaskPipeline\Concerns;

use App\Models\Event;
use Illuminate\Database\Eloquent\Model;

trait InteractsWithTaskMetadata
{
    /**
     * Get the metadata field name for the given model
     */
    protected function getMetadataField(Model $model): string
    {
        return $model instanceof Event ? 'event_metadata' : 'metadata';
    }

    /**
     * Get task executions from the model's metadata
     */
    protected function getTaskExecutions(Model $model): array
    {
        $field = $this->getMetadataField($model);
        $metadata = $model->$field ?? [];

        return $metadata['task_executions'] ?? [];
    }

    /**
     * Set task executions in the model's metadata
     */
    protected function setTaskExecutions(Model $model, array $executions): void
    {
        $field = $this->getMetadataField($model);
        $metadata = $model->$field ?? [];
        $metadata['task_executions'] = $executions;

        $model->withoutEvents(function () use ($model, $field, $metadata) {
            $model->update([$field => $metadata]);
        });
    }
}
