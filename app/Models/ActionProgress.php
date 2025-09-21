<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ActionProgress Model
 *
 * Tracks the progress of long-running operations in the application.
 * Supports any type of action through flexible action_type and action_id columns.
 *
 * Common action types:
 * - deletion: Data deletion operations
 * - migration: Database migrations
 * - sync: Data synchronization
 * - backup: Backup operations
 * - export: Data export
 * - import: Data import
 * - bulk_operation: Bulk user operations
 * - report: Report generation
 * - maintenance: System maintenance
 * - integration_test: API testing
 *
 * @property int $id
 * @property int $user_id
 * @property string $action_type
 * @property string $action_id
 * @property string $step
 * @property string $message
 * @property int $progress
 * @property int $total
 * @property array|null $details
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $failed_at
 * @property string|null $error_message
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ActionProgress extends Model
{
    protected $fillable = [
        'user_id',
        'action_type',
        'action_id',
        'step',
        'message',
        'progress',
        'total',
        'details',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    protected $casts = [
        'details' => 'array',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * Create a new action progress record
     *
     * @param  string  $userId  The user ID performing the action
     * @param  string  $actionType  The type of action (e.g., 'deletion', 'migration', 'sync')
     * @param  string  $actionId  Unique identifier for this specific action instance
     * @param  string  $step  Current step name (e.g., 'starting', 'processing', 'completed')
     * @param  string  $message  Human-readable progress message
     * @param  int  $progress  Current progress percentage (0-100)
     * @param  int  $total  Total progress (usually 100)
     * @param  array  $details  Additional metadata about the progress
     */
    public static function createProgress(
        string $userId,
        string $actionType,
        string $actionId,
        string $step,
        string $message,
        int $progress = 0,
        int $total = 100,
        array $details = []
    ): self {
        return self::create([
            'user_id' => $userId,
            'action_type' => $actionType,
            'action_id' => $actionId,
            'step' => $step,
            'message' => $message,
            'progress' => $progress,
            'total' => $total,
            'details' => $details,
        ]);
    }

    /**
     * Get the latest progress for a specific action
     *
     * @param  string  $userId  The user ID
     * @param  string  $actionType  The action type
     * @param  string  $actionId  The action ID
     */
    public static function getLatestProgress(
        string $userId,
        string $actionType,
        string $actionId
    ): ?self {
        return self::where('user_id', $userId)
            ->where('action_type', $actionType)
            ->where('action_id', $actionId)
            ->latest()
            ->first();
    }

    /**
     * Clean up old progress records (older than 24 hours)
     *
     * This method should be called regularly (e.g., daily) to prevent
     * the action_progress table from growing too large.
     *
     * @return int Number of records deleted
     */
    public static function cleanupOldRecords(): int
    {
        return self::where('created_at', '<', now()->subDay())
            ->delete();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isCompleted(): bool
    {
        return ! is_null($this->completed_at);
    }

    public function isFailed(): bool
    {
        return ! is_null($this->failed_at);
    }

    public function isInProgress(): bool
    {
        return ! $this->isCompleted() && ! $this->isFailed();
    }

    /**
     * Update progress for an existing action
     *
     * @param  string  $step  Current step name
     * @param  string  $message  Human-readable progress message
     * @param  int  $progress  Current progress percentage (0-100)
     * @param  array  $details  Additional metadata about the progress
     */
    public function updateProgress(
        string $step,
        string $message,
        int $progress,
        array $details = []
    ): void {
        $this->update([
            'step' => $step,
            'message' => $message,
            'progress' => $progress,
            'details' => $details,
        ]);
    }

    /**
     * Mark the action as completed
     *
     * @param  array  $details  Final completion details
     */
    public function markCompleted(array $details = []): void
    {
        $this->update([
            'completed_at' => now(),
            'step' => 'completed',
            'progress' => $this->total,
            'details' => array_merge($this->details ?? [], $details),
        ]);
    }

    /**
     * Mark the action as failed
     *
     * @param  string  $errorMessage  Error message describing the failure
     * @param  array  $details  Additional error details
     */
    public function markFailed(string $errorMessage, array $details = []): void
    {
        $this->update([
            'failed_at' => now(),
            'error_message' => $errorMessage,
            'step' => 'failed',
            'details' => array_merge($this->details ?? [], $details),
        ]);
    }
}
