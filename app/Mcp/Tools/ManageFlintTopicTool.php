<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Flint\FlintRunToken;
use App\Services\FlintTopicService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

#[Name('manage-flint-topic')]
class ManageFlintTopicTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Create, update, or list Flint Topics: long-lived strategic, thematic, or tactical threads. Optionally link a digest event or block that discussed a topic.';

    public function __construct(private FlintTopicService $topics) {}

    public function handle(Request $request): Response
    {
        $operation = $request->get('operation');
        if ($error = $this->requireAbility($request, $operation === 'list' ? 'flint:read' : 'flint:write')) {
            return $error;
        }

        $user = $request->user();
        if (! $user) {
            return Response::error('Authentication required.');
        }

        try {
            $runUuid = $this->runUuid($request);

            return match ($operation) {
                'create' => Response::json($this->topics->create($user, $request->all(), $runUuid)),
                'update' => $this->update($request, $runUuid),
                'list' => Response::json($this->topics->list($user, $request->all())),
                default => Response::error('operation must be create, update, or list.'),
            };
        } catch (ValidationException|RuntimeException $exception) {
            if ($exception instanceof ValidationException) {
                return Response::error($exception->validator->errors()->first());
            }

            return Response::error($exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->description('create, update, or list.')->required(),
            'id' => $schema->string()->description('Topic UUID, required for update.'),
            'title' => $schema->string()->description('Short, stable topic name. Required for create.'),
            'content' => $schema->string()->description('Running Markdown summary of the current understanding.'),
            'kind' => $schema->string()->description('strategic, thematic, or tactical. Required for create.'),
            'status' => $schema->string()->description('active, dormant, resolved, or expired.'),
            'next_review_at' => $schema->string()->description('Optional ISO date to revisit a dormant topic.'),
            'origin' => $schema->string()->description('conversation or digest_inference.'),
            'watching_for' => $schema->string()->description('What would move this thread on — the sentence a client should show verbatim without parsing it out of content.'),
            'related_event_id' => $schema->string()->description('Optional owned digest event UUID that discussed this topic.'),
            'related_block_id' => $schema->string()->description('Optional owned digest block UUID that discussed this topic.'),
            'run_token' => $schema->string()->description('Opaque topics run token. Supply it on routine-owned creates and updates.'),
        ];
    }

    private function update(Request $request, ?string $runUuid): Response
    {
        $id = $request->get('id');
        if (! is_string($id)) {
            return Response::error('id is required for update.');
        }

        $topic = $this->topics->update($request->user(), $id, $request->all(), $runUuid);

        return $topic ? Response::json($topic) : Response::error('Topic not found or access denied.');
    }

    private function runUuid(Request $request): ?string
    {
        $token = $request->get('run_token');
        if ($token === null) {
            return null;
        }
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('run_token must be a non-empty string.');
        }

        $claims = app(FlintRunToken::class)->verifyCompletion($token, $request->user());
        if (($claims['routine'] ?? null) !== 'topics') {
            throw new RuntimeException('The run token is not for the topics routine.');
        }

        return $claims['run_uuid'];
    }
}
