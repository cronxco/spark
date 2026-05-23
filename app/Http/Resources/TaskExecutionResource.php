<?php

namespace App\Http\Resources;

use App\Models\TaskExecution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TaskExecution
 */
class TaskExecutionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'entity_type' => $this->entity_type,
            'entity_id' => $this->entity_id,
            'task_key' => $this->task_key,
            'task_name' => $this->task_name,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'triggered_by' => $this->triggered_by,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'queue' => $this->queue,
            'queue_connection' => $this->queue_connection,
            'job_id' => $this->job_id,
            'error' => $this->error,
            'waiting_for' => $this->waiting_for,
            'blocked_by' => $this->blocked_by,
            'changed_fields' => $this->changed_fields,
            'history' => $this->history ?? [],
            'last_success' => $this->last_success,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
