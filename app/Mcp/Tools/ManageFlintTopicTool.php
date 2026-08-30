<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\FlintTopicService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

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
            return match ($operation) {
                'create' => Response::json($this->topics->create($user, $request->all())),
                'update' => $this->update($request),
                'list' => Response::json($this->topics->list($user, $request->all())),
                default => Response::error('operation must be create, update, or list.'),
            };
        } catch (ValidationException $exception) {
            return Response::error($exception->validator->errors()->first());
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
            'related_event_id' => $schema->string()->description('Optional owned digest event UUID that discussed this topic.'),
            'related_block_id' => $schema->string()->description('Optional owned digest block UUID that discussed this topic.'),
        ];
    }

    private function update(Request $request): Response
    {
        $id = $request->get('id');
        if (! is_string($id)) {
            return Response::error('id is required for update.');
        }

        $topic = $this->topics->update($request->user(), $id, $request->all());

        return $topic ? Response::json($topic) : Response::error('Topic not found or access denied.');
    }
}
