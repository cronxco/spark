<?php

namespace App\Observers;

use App\Models\Event;

class EventObserver
{
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
        // Embedding regeneration on updates is now handled by TaskPipeline
        // See GenerateEmbeddingTask in app/Jobs/TaskPipeline/Tasks/
    }
}
