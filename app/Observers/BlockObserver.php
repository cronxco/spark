<?php

namespace App\Observers;

use App\Jobs\GenerateBlockEmbeddingJob;
use App\Models\Block;

class BlockObserver
{
    /**
     * Handle the Block "created" event.
     */
    public function created(Block $block): void
    {
        // Dispatch job to generate embedding asynchronously
        GenerateBlockEmbeddingJob::dispatch($block);
    }

    /**
     * Handle the Block "updated" event.
     */
    public function updated(Block $block): void
    {
        // Check if relevant fields changed that would affect the embedding
        if ($block->wasChanged(['title', 'metadata', 'url', 'value', 'value_unit'])) {
            // Dispatch job to regenerate embedding
            GenerateBlockEmbeddingJob::dispatch($block);
        }
    }
}
