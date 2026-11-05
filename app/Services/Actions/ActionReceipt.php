<?php

namespace App\Services\Actions;

use App\Models\ActionProgress;
use App\Models\User;

/**
 * The server's record that a command ran, and what became of it. Built on
 * ActionProgress, with the command's own fields under `details.command`, so
 * no table or column is added.
 */
final readonly class ActionReceipt
{
    public const ACTION_TYPE = 'command';

    public function __construct(public ActionProgress $progress) {}

    public static function find(User $user, string $receiptId): ?self
    {
        $progress = ActionProgress::getLatestProgress((string) $user->id, self::ACTION_TYPE, $receiptId);

        return $progress === null ? null : new self($progress);
    }

    public function id(): string
    {
        return $this->progress->action_id;
    }

    public function actionId(): string
    {
        return (string) $this->command('action_id');
    }

    public function status(): ActionStatus
    {
        return ActionStatus::from((string) $this->command('status', ActionStatus::Running->value));
    }

    /** @return array<string, mixed> */
    public function target(): array
    {
        return (array) $this->command('target', []);
    }

    /** @return array<string, mixed>|null */
    public function undoPayload(): ?array
    {
        $undo = $this->command('undo');

        return is_array($undo) ? $undo : null;
    }

    public function isUndone(): bool
    {
        return $this->command('undone_at') !== null;
    }

    public function undoExpired(): bool
    {
        $expires = $this->undoPayload()['expires_at'] ?? null;

        return $expires === null || now()->greaterThan($expires);
    }

    public function canUndo(): bool
    {
        return $this->status() === ActionStatus::Succeeded
            && $this->undoPayload() !== null
            && ! $this->isUndone()
            && ! $this->undoExpired();
    }

    public function command(string $key, mixed $default = null): mixed
    {
        return data_get($this->progress->details, "command.{$key}", $default);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $failed = $this->status() === ActionStatus::Failed;

        return [
            'contract_version' => 1,
            'receipt_id' => $this->id(),
            'action_id' => $this->actionId(),
            'status' => $this->status()->value,
            'message' => $this->progress->message,
            'target' => (object) $this->target(),
            'result' => (object) (array) $this->command('result', []),
            'undo' => $this->undoPayload() === null ? null : [
                'available' => $this->canUndo(),
                'expires_at' => $this->undoPayload()['expires_at'],
                'undone_at' => $this->command('undone_at'),
            ],
            'error' => $failed ? [
                'code' => $this->command('error_code', 'failed'),
                'message' => $this->progress->error_message,
            ] : null,
            'created_at' => $this->progress->created_at?->toIso8601String(),
            'updated_at' => $this->progress->updated_at?->toIso8601String(),
        ];
    }
}
