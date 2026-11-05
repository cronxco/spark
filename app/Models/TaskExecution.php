<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskExecution extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'entity_type',
        'entity_id',
        'task_key',
        'task_name',
        'status',
        'attempts',
        'triggered_by',
        'started_at',
        'completed_at',
        'queue',
        'queue_connection',
        'job_id',
        'error',
        'waiting_for',
        'blocked_by',
        'changed_fields',
        'history',
        'last_success',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'changed_fields' => 'array',
        'history' => 'array',
        'last_success' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForEntity(Builder $query, string $entityType, string $entityId): Builder
    {
        return $query->where('entity_type', $entityType)->where('entity_id', $entityId);
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status ? $query->where('status', $status) : $query;
    }
}
