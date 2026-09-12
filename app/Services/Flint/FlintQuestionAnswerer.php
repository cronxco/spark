<?php

namespace App\Services\Flint;

use App\Models\Block;

/**
 * Records Will's answer to a `flint_user_question` block.
 *
 * Four surfaces write this answer — the Livewire component on the web, the
 * mobile API, the legacy public API, and the MCP tool — and each had its own
 * copy of the same three-key metadata merge. They agreed today, which is the
 * only reason nothing had broken; the shape of a question's answer is a fact
 * about the model, not about the transport that happened to carry it.
 */
class FlintQuestionAnswerer
{
    /**
     * @return array<string, mixed> the recorded answer, for an API response
     */
    public function record(Block $block, string $answer, ?string $note = null): array
    {
        $answeredAt = now()->toIso8601String();

        $block->metadata = array_merge($block->metadata ?? [], [
            'answer' => $answer,
            'answer_note' => $note ?: null,
            'answered_at' => $answeredAt,
        ]);

        $block->save();

        return [
            'block_id' => $block->id,
            'answer' => $answer,
            'answer_note' => $note ?: null,
            'answered_at' => $answeredAt,
        ];
    }

    /** Whether this block is a question, and therefore answerable at all. */
    public function isQuestion(Block $block): bool
    {
        return $block->block_type === 'flint_user_question';
    }
}
