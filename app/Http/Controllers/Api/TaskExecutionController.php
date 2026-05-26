<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaskExecutionResource;
use App\Models\TaskExecution;
use Illuminate\Http\Request;

class TaskExecutionController extends Controller
{
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string'],
            'task_key' => ['nullable', 'string'],
            'entity_type' => ['nullable', 'string', 'in:event,block,object,integration'],
            'entity_id' => ['nullable', 'uuid'],
            'queue' => ['nullable', 'string'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = TaskExecution::query()
            ->forUser($request->user()->id)
            ->when($validated['status'] ?? null, fn ($query, $value) => $query->where('status', $value))
            ->when($validated['task_key'] ?? null, fn ($query, $value) => $query->where('task_key', $value))
            ->when($validated['entity_type'] ?? null, fn ($query, $value) => $query->where('entity_type', $value))
            ->when($validated['entity_id'] ?? null, fn ($query, $value) => $query->where('entity_id', $value))
            ->when($validated['queue'] ?? null, fn ($query, $value) => $query->where('queue', $value))
            ->when($validated['from'] ?? null, fn ($query, $value) => $query->where('updated_at', '>=', $value))
            ->when($validated['to'] ?? null, fn ($query, $value) => $query->where('updated_at', '<=', $value))
            ->latest('updated_at');

        return TaskExecutionResource::collection(
            $query->paginate($validated['per_page'] ?? 25)
        );
    }

    public function show(Request $request, TaskExecution $taskExecution)
    {
        abort_unless($taskExecution->user_id === $request->user()->id, 404);

        return new TaskExecutionResource($taskExecution);
    }
}
