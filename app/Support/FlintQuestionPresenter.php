<?php

namespace App\Support;

use App\Models\Block;
use App\Services\Api\ResourceVersion;

class FlintQuestionPresenter
{
    /** @return array<string, mixed> */
    public static function present(Block $block): array
    {
        $block->loadMissing('event');
        $metadata = $block->metadata ?? [];
        $history = collect(FlintQuestion::history($block))->map(fn (array $action): array => [
            'id' => $action['id'],
            'action' => $action['action'],
            'answer' => $action['answer'] ?? null,
            'context' => $action['context'] ?? null,
            'created_at' => $action['created_at'] ?? null,
        ])->values()->all();
        $status = FlintQuestion::status($block);

        return [
            'id' => (string) $block->id,
            'digest_id' => (string) $block->event_id,
            'source_digest' => [
                'local_date' => data_get($block->event?->event_metadata, 'local_date', $block->event?->time?->toDateString()),
                'period' => data_get($block->event?->event_metadata, 'period'),
            ],
            'status' => $status,
            // MR-9: the short title a digest block already carries — the
            // digest itself shows this, not the full `question` text.
            'title' => $block->title,
            'question' => $metadata['question'] ?? $block->title,
            'topic' => $metadata['topic'] ?? null,
            'answer_options' => $metadata['answer_options'] ?? null,
            'asked_at' => $block->time?->toIso8601String() ?? $block->created_at?->toIso8601String(),
            'effective_answer' => $status === 'answered' ? [
                'answer' => $metadata['answer'] ?? null,
                'context' => $metadata['answer_note'] ?? null,
                'answered_at' => $metadata['answered_at'] ?? null,
            ] : null,
            'answer_history' => $history,
            'version' => app(ResourceVersion::class)->etag($block),
        ];
    }
}
