<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\RequiresSparkAbility;
use App\Services\Flint\FlintRunToken;
use App\Services\FlintTopicService;
use App\Services\FlintTopicTaskService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Exceptions\HttpResponseException;
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

    protected string $description = 'Create, update, or list Flint Topics and add or update dated task blocks on a Topic. Optionally link digest evidence.';

    public function __construct(private FlintTopicService $topics, private FlintTopicTaskService $tasks) {}

    public function handle(Request $request): Response
    {
        $operation = $request->get('operation');
        if ($error = $this->requireAbility($request, in_array($operation, ['list', 'list_tasks'], true) ? 'flint:read' : 'flint:write')) {
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
                'list_tasks' => $this->listTasks($request),
                'add_task' => $this->addTask($request),
                'update_task' => $this->updateTask($request),
                default => Response::error('operation must be create, update, list, list_tasks, add_task, or update_task.'),
            };
        } catch (ValidationException|RuntimeException|HttpResponseException $exception) {
            if ($exception instanceof ValidationException) {
                return Response::error($exception->validator->errors()->first());
            }

            return Response::error($exception instanceof HttpResponseException
                ? ($exception->getResponse()->getData(true)['message'] ?? 'Task version conflict.')
                : $exception->getMessage());
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->description('create, update, list, list_tasks, add_task, or update_task.')->required(),
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
            'task_id' => $schema->string()->description('Task block UUID, required for update_task.'),
            'task_version' => $schema->string()->description('Version from the task payload, required for update_task.'),
            'due_on' => $schema->string()->description('Optional YYYY-MM-DD due date for the task.'),
            'review_on' => $schema->string()->description('Optional YYYY-MM-DD date to revisit the task.'),
            'completed' => $schema->boolean()->description('Completion state for update_task.'),
            'client_mutation_id' => $schema->string()->description('Stable UUID for an idempotent add_task retry.'),
        ];
    }

    private function listTasks(Request $request): Response
    {
        $id = $request->get('id');
        if (! is_string($id) || ! $this->topics->detail($request->user(), $id)) {
            return Response::error('Topic not found or access denied.');
        }

        return Response::json(['data' => $this->tasks->list($request->user(), $id)]);
    }

    private function addTask(Request $request): Response
    {
        $id = $request->get('id');
        if (! is_string($id)) {
            return Response::error('Topic id is required for add_task.');
        }
        $data = validator($request->all(), [
            'client_mutation_id' => ['required', 'uuid'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['nullable', 'string', 'max:20000'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'review_on' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();
        $task = $this->tasks->create($request->user(), $id, $data);

        return $task ? Response::json($task) : Response::error('Topic not found or access denied.');
    }

    private function updateTask(Request $request): Response
    {
        $id = $request->get('id');
        $taskId = $request->get('task_id');
        $version = $request->get('task_version');
        if (! is_string($id) || ! is_string($taskId) || ! is_string($version)) {
            return Response::error('Topic id, task_id and task_version are required for update_task.');
        }
        $data = validator($request->all(), [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'review_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'completed' => ['sometimes', 'required', 'boolean'],
        ])->validate();
        $task = $this->tasks->update($request->user(), $id, $taskId, $data, $version);

        return $task ? Response::json($task) : Response::error('Task not found or access denied.');
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
