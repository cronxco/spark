<?php

namespace App\Observers;

use App\Jobs\TaskPipeline\ProcessTaskPipelineJob;
use App\Models\Block;

class BlockObserver
{
    private const DERIVED_DATA_FIELDS = [
        'event_id',
        'block_type',
        'time',
        'title',
        'metadata',
        'url',
        'value',
        'value_multiplier',
        'value_unit',
    ];

    /**
     * Handle the Block "created" event.
     */
    public function created(Block $block): void
    {
        if (! config('app.enable_task_pipeline', true)) {
            return;
        }

        // Embedding generation is now handled by TaskPipeline
        // See GenerateEmbeddingTask in app/Jobs/TaskPipeline/Tasks/

        // If this is a summary/details block, trigger parent event's task pipeline
        // This ensures events get re-embedded when AI summaries are added
        if ($this->isSummaryOrDetailsBlock($block) && $block->event) {
            ProcessTaskPipelineJob::dispatch(
                model: $block->event,
                trigger: 'updated',
                taskFilter: ['generate_embedding'],
                force: true,
                changedFields: ['blocks.created'],
            )->onQueue('tasks');
        }
    }

    /**
     * Handle the Block "updated" event.
     */
    public function updated(Block $block): void
    {
        if (! config('app.enable_task_pipeline', true)) {
            return;
        }

        $changedFields = $this->changedDerivedDataFields($block);

        if ($changedFields === []) {
            return;
        }

        ProcessTaskPipelineJob::dispatch(
            model: $block,
            trigger: 'updated',
            force: true,
            changedFields: $changedFields,
        )->onQueue('tasks');

        // If this is a summary/details block and relevant fields changed, trigger parent event's task pipeline
        if (($this->isSummaryOrDetailsBlock($block) || $this->wasSummaryOrDetailsBlock($block)) && $block->event) {
            ProcessTaskPipelineJob::dispatch(
                model: $block->event,
                trigger: 'updated',
                taskFilter: ['generate_embedding'],
                force: true,
                changedFields: array_map(fn (string $field) => "blocks.{$field}", $changedFields),
            )->onQueue('tasks');
        }
    }

    /**
     * Check if this block is a summary or details block
     */
    private function isSummaryOrDetailsBlock(Block $block): bool
    {
        return $this->isSummaryOrDetailsBlockType($block->block_type);
    }

    private function wasSummaryOrDetailsBlock(Block $block): bool
    {
        return $this->isSummaryOrDetailsBlockType($block->getOriginal('block_type'));
    }

    private function isSummaryOrDetailsBlockType(?string $blockType): bool
    {
        if (empty($blockType)) {
            return false;
        }

        $blockType = strtolower($blockType);

        return str_contains($blockType, 'summary') || str_contains($blockType, 'details');
    }

    /**
     * @return array<int, string>
     */
    private function changedDerivedDataFields(Block $block): array
    {
        return array_values(array_filter(
            self::DERIVED_DATA_FIELDS,
            fn (string $field) => $block->wasChanged($field),
        ));
    }
}
