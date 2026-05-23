<?php

namespace App\Observers;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\EventObject;

class EventObjectObserver
{
    private const DERIVED_DATA_FIELDS = [
        'time',
        'concept',
        'type',
        'title',
        'content',
        'url',
    ];

    /**
     * Handle the EventObject "created" event.
     */
    public function created(EventObject $object): void
    {
        // Embedding generation is now handled by TaskPipeline
        // See GenerateEmbeddingTask in app/Jobs/TaskPipeline/Tasks/
    }

    /**
     * Handle the EventObject "updated" event.
     */
    public function updated(EventObject $object): void
    {
        if (! config('app.enable_task_pipeline', true)) {
            return;
        }

        $changedFields = $this->changedDerivedDataFields($object);

        if ($changedFields === []) {
            return;
        }

        ProcessTaskPipelineJob::dispatch(
            model: $object,
            trigger: 'updated',
            force: true,
            changedFields: $changedFields,
        )->onQueue('tasks');
    }

    /**
     * @return array<int, string>
     */
    private function changedDerivedDataFields(EventObject $object): array
    {
        return array_values(array_filter(
            self::DERIVED_DATA_FIELDS,
            fn (string $field) => $object->wasChanged($field),
        ));
    }
}
