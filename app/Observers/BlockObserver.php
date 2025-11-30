<?php

namespace App\Observers;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Block;

class BlockObserver
{
    /**
     * Handle the Block "created" event.
     */
    public function created(Block $block): void
    {
        // Embedding generation is now handled by TaskPipeline
        // See GenerateEmbeddingTask in app/Jobs/TaskPipeline/Tasks/

        // If this is a summary/details block, trigger parent event's task pipeline
        // This ensures events get re-embedded when AI summaries are added
        if ($this->isSummaryOrDetailsBlock($block) && $block->event) {
            ProcessTaskPipelineJob::dispatch($block->event, 'updated', ['generate_embedding'], force: true)
                ->onQueue('tasks');
        }
    }

    /**
     * Handle the Block "updated" event.
     */
    public function updated(Block $block): void
    {
        // Embedding regeneration is now handled by TaskPipeline
        // See GenerateEmbeddingTask in app/Jobs/TaskPipeline/Tasks/

        // If this is a summary/details block and relevant fields changed, trigger parent event's task pipeline
        if ($block->wasChanged(['title', 'metadata', 'url', 'value', 'value_unit'])) {
            if ($this->isSummaryOrDetailsBlock($block) && $block->event) {
                ProcessTaskPipelineJob::dispatch($block->event, 'updated', ['generate_embedding'], force: true)
                    ->onQueue('tasks');
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
