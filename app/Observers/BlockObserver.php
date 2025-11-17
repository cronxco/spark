<?php

namespace App\Observers;

use App\Jobs\GenerateBlockEmbeddingJob;
use App\Jobs\GenerateEventEmbeddingJob;
use App\Models\Block;

class BlockObserver
{
    /**
     * Handle the Block "created" event.
     */
    public function created(Block $block): void
    {
        // Only dispatch if embeddings are enabled (API key is configured)
        if (config('services.openai.api_key')) {
            // Dispatch job to generate embedding asynchronously
            GenerateBlockEmbeddingJob::dispatch($block)->onQueue('embeddings');

            // If this is a summary/details block, regenerate parent event's embedding
            // This ensures events include AI-generated summaries in their embeddings
            if ($this->isSummaryOrDetailsBlock($block)) {
                GenerateEventEmbeddingJob::dispatch($block->event)->onQueue('embeddings');
            }
        }
    }

    /**
     * Handle the Block "updated" event.
     */
    public function updated(Block $block): void
    {
        // Only dispatch if embeddings are enabled (API key is configured)
        if (config('services.openai.api_key')) {
            // Check if relevant fields changed that would affect the embedding
            if ($block->wasChanged(['title', 'metadata', 'url', 'value', 'value_unit'])) {
                // Dispatch job to regenerate embedding
                GenerateBlockEmbeddingJob::dispatch($block)->onQueue('embeddings');

                // If this is a summary/details block, also regenerate parent event's embedding
                if ($this->isSummaryOrDetailsBlock($block)) {
                    GenerateEventEmbeddingJob::dispatch($block->event)->onQueue('embeddings');
                }
            }
        }
    }

    /**
     * Check if this block is a summary or details block
     */
    private function isSummaryOrDetailsBlock(Block $block): bool
    {
        if (empty($block->block_type)) {
            return false;
        }

        $blockType = strtolower($block->block_type);

        return str_contains($blockType, 'summary') || str_contains($blockType, 'details');
    }
}
