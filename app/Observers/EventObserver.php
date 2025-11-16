<?php

namespace App\Observers;

use App\Jobs\GenerateEventEmbeddingJob;
use App\Models\Event;

class EventObserver
{
    /**
     * Handle the Event "created" event.
     */
    public function created(Event $event): void
    {
        // Only dispatch if embeddings are enabled (API key is configured)
        if (config('services.openai.api_key')) {
            GenerateEventEmbeddingJob::dispatch($event);
        }
    }

    /**
     * Handle the Event "updated" event.
     */
    public function updated(Event $event): void
    {
        // Only dispatch if embeddings are enabled (API key is configured)
        if (config('services.openai.api_key')) {
            // Check if relevant fields changed that would affect the embedding
            if ($event->wasChanged(['service', 'domain', 'action', 'value', 'value_unit'])) {
                // Dispatch job to regenerate embedding
                GenerateEventEmbeddingJob::dispatch($event);
            }
        }
    }
}
