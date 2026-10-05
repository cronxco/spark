<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Models\Block;
use App\Services\Api\ResourceVersion;
use App\Services\Flint\FlintQuestionActionService;
use App\Services\Flint\FlintQuestionAnswerer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('answer-flint-question')]
class AnswerFlintQuestionTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Answer a Flint user-question block, optionally with a note, or mark it not relevant with action "skip". Answering an answered question adds a correction and keeps the earlier answer. Use after retrieving a digest with get-latest-flint-digest.';

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

        if ($request->get('action') === 'skip') {
            $result = app(FlintQuestionActionService::class)->record(
                $request->user(),
                (string) $block->id,
                ['action' => 'skip'],
                app(ResourceVersion::class)->etag($block),
                (string) Str::uuid(),
            );

            return $result['status'] >= 400
                ? Response::error($result['message'] ?? 'The Flint question could not be skipped.')
                : Response::json($result['data']);
        }

        $answer = trim((string) $request->get('answer'));
        if ($answer === '' || mb_strlen($answer) > 1000) {
            return Response::error('answer is required and must not exceed 1000 characters.');
        }

        $note = $request->get('answer_note');
        if ($note !== null && (! is_string($note) || mb_strlen($note) > 1000)) {
            return Response::error('answer_note must be a string no longer than 1000 characters.');
        }

        return Response::json(
            app(FlintQuestionAnswerer::class)->record($block, $answer, $note)
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'block_id' => $schema->string()->description('UUID of a flint_user_question block.')->required(),
            'action' => $schema->string()->enum(['answer', 'skip'])->description('answer (default) or skip, which marks an open question not relevant.'),
            'answer' => $schema->string()->description('The user answer. Required unless action is skip.'),
            'answer_note' => $schema->string()->description('Optional supporting note.'),
        ];
    }
}
