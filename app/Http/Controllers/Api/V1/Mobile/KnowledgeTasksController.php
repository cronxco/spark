<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\EffectiveTimezoneResolver;
use App\Services\Knowledge\ProjectService;
use App\Services\Knowledge\TaskService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class KnowledgeTasksController extends Controller
{
    public function index(Request $request, TaskService $tasks)
    {
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'], 'status' => ['nullable', 'in:open,completed,cancelled,expired,deleted'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'], 'cursor' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'], 'thread_id' => ['nullable', 'uuid'],
            'inbox' => ['nullable', 'boolean'], 'q' => ['nullable', 'string', 'max:255'],
            'include_deleted' => ['nullable', 'boolean'],
        ]);
        $deleted = ($data['status'] ?? '') === 'deleted' || ($data['include_deleted'] ?? false);
        $query = $tasks->query($request->user(), $deleted)
            ->with('event.integration')->orderBy('blocks.id');
        if (! empty($data['cursor'])) {
            $query->where('blocks.id', '>', $data['cursor']);
        }
        if (! empty($data['q'])) {
            $query->where(fn ($q) => $q->where('title', 'ilike', '%' . $data['q'] . '%')->orWhere('metadata->task->content', 'ilike', '%' . $data['q'] . '%'));
        }
        $limit = $data['limit'] ?? 50;
        // Bounded scan: a filtered empty page may still have a continuation.
        $raw = $query->limit(200)->get();
        $result = [];
        $last = null;
        foreach ($raw as $block) {
            $last = $block->id;
            $p = $tasks->payload($block, $data['date'] ?? null);
            if (isset($data['date']) && ! $p['visible_on_date']) {
                continue;
            }
            if (isset($data['status']) && $p['status'] !== $data['status']) {
                continue;
            }
            if (! empty($data['inbox']) && ($p['availability_on'] !== null || $p['status'] !== 'open')) {
                continue;
            }
            if (isset($data['project_id']) && $p['project_id'] !== $data['project_id']) {
                continue;
            }
            if (isset($data['thread_id']) && ! in_array($data['thread_id'], $p['thread_ids'])) {
                continue;
            }
            $result[] = $p;
            if (count($result) >= $limit) {
                break;
            }
        }
        $more = $last && $tasks->query($request->user(), $deleted)->where('blocks.id', '>', $last)->exists();

        return response()->json(['data' => $result, 'next_cursor' => $more ? $last : null, 'has_more' => (bool) $more,
            'local_date' => app(EffectiveTimezoneResolver::class)->today($request->user())->toDateString(),
            'schedule_timezone' => app(EffectiveTimezoneResolver::class)->timezoneFor($request->user())]);
    }

    public function store(Request $request, TaskService $tasks)
    {
        return response()->json(['data' => $tasks->create($request->user(), $request->all())], 201);
    }

    public function show(Request $request, string $id, TaskService $tasks)
    {
        return response()->json(['data' => $tasks->payload($tasks->find($request->user(), $id, true))]);
    }

    public function update(Request $request, string $id, TaskService $tasks)
    {
        return response()->json(['data' => $tasks->update($request->user(), $id, $request->all(), $request->header('If-Match'))]);
    }

    public function action(Request $request, string $id, TaskService $tasks)
    {
        $data = $request->validate(['action' => ['required', 'in:complete,reopen,cancel,reactivate,delete,restore,retry_sync']]);

        return response()->json(['data' => $tasks->act($request->user(), $id, $data['action'], $request->header('If-Match'), $request->all())]);
    }

    public function bulk(Request $request, TaskService $tasks)
    {
        $data = $request->validate(['commands' => ['required', 'array', 'max:100'], 'commands.*.id' => ['required', 'uuid'],
            'commands.*.version' => ['required', 'string'], 'commands.*.action' => ['required', 'in:complete,reopen,cancel,reactivate,delete,restore,retry_sync,edit'],
            'commands.*.client_mutation_id' => ['sometimes', 'uuid'], 'commands.*.availability_on' => ['sometimes', 'date_format:Y-m-d'], 'commands.*.changes' => ['sometimes', 'array']]);
        $results = [];
        foreach ($data['commands'] as $c) {
            try {
                $results[] = ['id' => $c['id'], 'data' => $c['action'] === 'edit'
                    ? $tasks->update($request->user(), $c['id'], $c['changes'] ?? [], $c['version'])
                    : $tasks->act($request->user(), $c['id'], $c['action'], $c['version'], $c)];
            } catch (HttpResponseException $e) {
                $results[] = ['id' => $c['id'], 'error' => $e->getResponse()->getStatusCode()];
            } catch (ModelNotFoundException $e) {
                $results[] = ['id' => $c['id'], 'error' => 404];
            } catch (ValidationException $e) {
                $results[] = ['id' => $c['id'], 'error' => 422, 'messages' => $e->errors()];
            } catch (HttpException $e) {
                $results[] = ['id' => $c['id'], 'error' => $e->getStatusCode()];
            }
        }

        return response()->json(['data' => $results]);
    }

    public function history(Request $request, string $id, TaskService $tasks)
    {
        return response()->json($tasks->history($request->user(), $id)->simplePaginate(50)->through(fn ($event) => ['id' => $event->id, 'action' => $event->action, 'time' => $event->time?->toIso8601String(), 'change' => $event->event_metadata]));
    }

    public function projects(Request $request, ProjectService $projects)
    {
        $rows = $projects->query($request->user())->orderBy('id')->cursorPaginate(50);

        return response()->json(['data' => $rows->getCollection()->map(fn ($p) => $projects->payload($p)), 'has_more' => $rows->hasMorePages(), 'next_cursor' => $rows->nextCursor()?->encode()]);
    }

    public function projectStore(Request $request, ProjectService $projects)
    {
        return response()->json(['data' => $projects->save($request->user(), $request->all())], 201);
    }

    public function projectShow(Request $request, string $id, ProjectService $projects)
    {
        return response()->json(['data' => $projects->payload($projects->query($request->user())->findOrFail($id))]);
    }

    public function projectUpdate(Request $request, string $id, ProjectService $projects)
    {
        return response()->json(['data' => $projects->save($request->user(), $request->all(), $id, $request->header('If-Match'))]);
    }

    public function projectDelete(Request $request, string $id, ProjectService $projects)
    {
        $projects->delete($request->user(), $id, $request->header('If-Match'));

        return response()->noContent();
    }
}
