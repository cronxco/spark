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
        // Only dispatch if embeddings are enabled (API key is configured)
        if (config('services.openai.api_key')) {
            GenerateObjectEmbeddingJob::dispatch($object);
        }
    }

    /**
     * Handle the EventObject "updated" event.
     */
    public function updated(EventObject $object): void
    {
        // Only dispatch if embeddings are enabled (API key is configured)
        if (config('services.openai.api_key')) {
            // Check if relevant fields changed that would affect the embedding
            if ($object->wasChanged(['concept', 'type', 'title', 'content', 'url'])) {
                // Dispatch job to regenerate embedding
                GenerateObjectEmbeddingJob::dispatch($object);
            }
        }
    }
}
