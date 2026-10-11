<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Knowledge\ProjectService;
use App\Services\Knowledge\TaskProposalService;
use App\Services\Knowledge\TaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Name('knowledge-tasks')]
class KnowledgeTasksTool extends Tool
{
    use RequiresSparkAbility;

    protected string $description = 'Read owned tasks/projects or capture, edit and act on tasks with expected versions. Use propose for inferred Flint tasks; these wait in Review for approval. Integration recovery tasks resolve only after verified recovery. Task title/content changes from Outline have visible sync status.';

    public function handle(Request $request): Response
    {
        $operation = $request->get('operation');
        if ($error = $this->requireAbility($request, in_array($operation, ['list', 'projects', 'show']) ? 'data:read' : 'data:write')) {
            return $error;
        }
        $user = $request->user();
        $tasks = app(TaskService::class);
        $fields = $request->get('fields', []);
        try {
            $result = match ($operation) {
                'list' => $tasks->query($user)->orderBy('id')->when($request->get('cursor'), fn ($q, $cursor) => $q->where('id', '>', $cursor))->limit(50)->get()->map(fn ($b) => $tasks->payload($b, $request->get('date')))->all(),
                'projects' => app(ProjectService::class)->query($user)->orderBy('id')->limit(50)->get()->map(fn ($p) => app(ProjectService::class)->payload($p))->all(),
                'show' => $tasks->payload($tasks->find($user, $request->get('id'), true)),
                'create' => $tasks->create($user, $fields),
                'edit' => $tasks->update($user, $request->get('id'), $fields, $request->get('version')),
                'action' => $tasks->act($user, $request->get('id'), $request->get('action'), $request->get('version'), $fields),
                'propose' => app(TaskProposalService::class)->propose($user, $fields),
                default => throw new \InvalidArgumentException('Unknown operation.'),
            };

            return Response::json(['data' => $result]);
        } catch (ValidationException $e) {
            return Response::error($e->getMessage());
        } catch (HttpResponseException $e) {
            return Response::error('Task conflict: '.$e->getResponse()->getContent());
        } catch (ModelNotFoundException $e) {
            return Response::error('Task or linked entity not found.');
        } catch (HttpException|\InvalidArgumentException $e) {
            return Response::error($e->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return ['operation' => $schema->string()->enum(['list', 'projects', 'show', 'create', 'edit', 'action', 'propose'])->required(),
            'id' => $schema->string(), 'version' => $schema->string(), 'cursor' => $schema->string(), 'date' => $schema->string(), 'action' => $schema->string(),
            'fields' => $schema->object()->description('Task fields: title, client_mutation_id UUID (required for create), content, lifecycle day/week/persistent, availability_on, due_on, project_id, thread_ids, context, priority, recurrence, remind_at. Proposals also need reason and stable source_key.')];
    }
}
