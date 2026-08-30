<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Models\Block;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('answer-flint-question')]
class AnswerFlintQuestionTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Answer a Flint user-question block, optionally with a note. Use after retrieving a digest with get-latest-flint-digest.';

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'flint:write')) {
            return $error;
        }

        $blockId = $request->get('block_id');
        if (! is_string($blockId)) {
            return Response::error('block_id is required.');
        }

        $block = Block::with('event.integration')->find($blockId);
        if (! $block || $block->event?->integration?->user_id !== $request->user()->id) {
            return Response::error('Flint question not found or access denied.');
        }
        if ($block->block_type !== 'flint_user_question') {
            return Response::error('The block is not a Flint user question.');
        }

        $answer = trim((string) $request->get('answer'));
        if ($answer === '' || mb_strlen($answer) > 1000) {
            return Response::error('answer is required and must not exceed 1000 characters.');
        }

        $note = $request->get('answer_note');
        if ($note !== null && (! is_string($note) || mb_strlen($note) > 1000)) {
            return Response::error('answer_note must be a string no longer than 1000 characters.');
        }

        $answeredAt = now()->toIso8601String();
        $block->metadata = array_merge($block->metadata ?? [], [
            'answer' => $answer,
            'answer_note' => $note,
            'answered_at' => $answeredAt,
        ]);
        $block->save();

        return Response::json([
            'block_id' => $block->id,
            'answer' => $answer,
            'answer_note' => $note,
            'answered_at' => $answeredAt,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'block_id' => $schema->string()->description('UUID of a flint_user_question block.')->required(),
            'answer' => $schema->string()->description('The user answer.')->required(),
            'answer_note' => $schema->string()->description('Optional supporting note.'),
        ];
    }
}
