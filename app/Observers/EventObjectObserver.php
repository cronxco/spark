<?php

namespace App\Observers;

use App\Jobs\GenerateObjectEmbeddingJob;
use App\Models\EventObject;

class EventObjectObserver
{
    /**
     * Handle the EventObject "created" event.
     */
    public function created(EventObject $object): void
    {
        // Dispatch job to generate embedding asynchronously
        GenerateObjectEmbeddingJob::dispatch($object);
    }

    /**
     * Handle the EventObject "updated" event.
     */
    public function updated(EventObject $object): void
    {
        // Check if relevant fields changed that would affect the embedding
        if ($object->wasChanged(['concept', 'type', 'title', 'content', 'url'])) {
            // Dispatch job to regenerate embedding
            GenerateObjectEmbeddingJob::dispatch($object);
        }
    }
}
