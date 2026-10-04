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
        $block->loadMissing('event.integration');
        $user = $block->event?->integration?->user;
        if (! $user) {
            throw new FlintQuestionActionException(422, 'The Flint question has no owning user.');
        }

        $result = app(FlintQuestionActionService::class)->recordLegacy($user, (string) $block->id, $answer, $note);
        if ($result['status'] >= 400) {
            throw new FlintQuestionActionException(
                $result['status'],
                $result['message'] ?? 'The Flint question could not be answered.',
            );
        }
        $effective = $result['data']['effective_answer'];

        return [
            'block_id' => $block->id,
            'answer' => $effective['answer'],
            'answer_note' => $effective['context'],
            'answered_at' => $effective['answered_at'],
        ];
    }

    /** Whether this block is a question, and therefore answerable at all. */
    public function isQuestion(Block $block): bool
    {
        return $block->block_type === 'flint_user_question';
    }
}
