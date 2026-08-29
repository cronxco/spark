<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\FlintDigestService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-flint-digest')]
class CreateFlintDigestTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = <<<'MARKDOWN'
        Create a Flint digest event with attached blocks.
        Use this to record an AI-generated digest, including user questions (flint_user_question)
        and editorial notes (flint_editorial_note) alongside standard content blocks.

        Block types:
        - `flint_user_question`: A question for the user. Provide `question`, optional `topic`,
          `priority` (low/medium/high), and optional `answer_options` array.
        - `flint_editorial_note`: Freeform AI commentary. Provide `content` (markdown).
        - Any `flint_*` type: Provide `content` (markdown) for the block body.

        Calls create a new digest. Do not retry after an unknown outcome without
        checking get-latest-flint-digest first.
    MARKDOWN;

    public function __construct(private FlintDigestService $digests) {}

    public function handle(Request $request): Response
    {
        if ($error = $this->requireAbility($request, 'flint:write')) {
            return $error;
        }

        $user = $request->user();

        if (! $user) {
            return Response::error('Authentication required.');
        }

        try {
            $payload = $request->all();
            if (($payload['date'] ?? null) === 'today') {
                $payload['date'] = now()->toDateString();
            }

            return Response::json($this->digests->create($user, $payload));
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()
                ->description('Title for the digest (e.g. "Morning Digest — May 10").')
                ->required(),

            'period' => $schema->string()
                ->description('Time period: morning, afternoon, or evening. Inferred from current time if omitted.'),

            'date' => $schema->string()
                ->description('ISO date for the digest (e.g. "2026-05-10"). Defaults to today.')
                ->default('today'),

            'summary' => $schema->string()
                ->description('Optional headline summary content for the digest.'),

            'blocks' => $schema->array()
                ->items($schema->object([
                    'block_type' => $schema->string()
                        ->required()
                        ->description('Block type (e.g. flint_user_question, flint_editorial_note, flint_insight).'),
                    'title' => $schema->string()
                        ->required()
                        ->description('Block title.'),
                    'content' => $schema->string()
                        ->description('Markdown content — for flint_editorial_note and other content blocks.'),
                    'referenced_event_ids' => $schema->array()
                        ->items($schema->string())
                        ->description('Event UUIDs this block draws on. Surfaced to the client as tappable reference chips and linkified inline in the content.'),
                    'question' => $schema->string()
                        ->description('For flint_user_question: the question text to display to the user.'),
                    'topic' => $schema->string()
                        ->description('For flint_user_question: category (e.g. health, money, routine).'),
                    'priority' => $schema->string()
                        ->description('For flint_user_question: low, medium, or high.'),
                    'answer_options' => $schema->array()
                        ->items($schema->string())
                        ->description('For flint_user_question: optional multiple-choice answers. Omit for freeform.'),
                ]))
                ->description('Blocks to attach to this digest.'),
        ];
    }
}
