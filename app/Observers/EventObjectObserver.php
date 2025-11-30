<?php

namespace App\Observers;

use App\Models\EventObject;

class EventObjectObserver
{
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
        // Embedding regeneration on updates is now handled by TaskPipeline
        // See GenerateEmbeddingTask in app/Jobs/TaskPipeline/Tasks/
    }
}
