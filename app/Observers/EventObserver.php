<?php

namespace App\Observers;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Event;

class EventObserver
{
    private const DERIVED_DATA_FIELDS = [
        'time',
        'service',
        'domain',
        'action',
        'value',
        'value_multiplier',
        'value_unit',
        'actor_id',
        'target_id',
    ];

    /**
     * Handle the Event "created" event.
     */
    public function created(Event $event): void
    {
        // Embedding generation is now handled by TaskPipeline
        // See GenerateEmbeddingTask in app/Jobs/TaskPipeline/Tasks/
    }

    /**
     * Handle the Event "updated" event.
     */
    public function updated(Event $event): void
    {
        if (! config('app.enable_task_pipeline', true)) {
            return;
        }

        $changedFields = $this->changedDerivedDataFields($event);

        if ($changedFields === []) {
            return;
        }

        ProcessTaskPipelineJob::dispatch(
            model: $event,
            trigger: 'updated',
            force: true,
            changedFields: $changedFields,
        )->onQueue('tasks');
    }

    /**
     * @return array<int, string>
     */
    private function changedDerivedDataFields(Event $event): array
    {
        return array_values(array_filter(
            self::DERIVED_DATA_FIELDS,
            fn (string $field) => $event->wasChanged($field),
        ));
    }
}
