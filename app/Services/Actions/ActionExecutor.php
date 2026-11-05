<?php

namespace App\Services\Actions;

use App\Models\ActionProgress;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Runs a registry action and records a receipt. Capability checks belong to
 * the caller (the API checks the token; the web session has none), so this
 * only enforces what the registry declares: the target shape and the
 * confirm policy.
 */
class ActionExecutor
{
    /** How long an Undo stays available. */
    public const UNDO_WINDOW_MINUTES = 15;

    private const IDEMPOTENCY_HOURS = 24;

    /**
     * @param  array<string, mixed>  $target
     *
     * @throws ConfirmationRequired
     * @throws ValidationException
     */
    public function run(
        User $user,
        ActionDefinition $action,
        array $target = [],
        bool $confirmed = false,
        ?string $idempotencyKey = null,
    ): ActionReceipt {
        if ($action->handler === null) {
            throw new InvalidArgumentException("{$action->id} has no server handler; the client runs it.");
        }

        $validated = Validator::make($target, $action->rules())->validate();

        if ($action->confirm === ConfirmPolicy::Confirm && ! $confirmed) {
            throw new ConfirmationRequired($action);
        }

        if ($idempotencyKey !== null) {
            $lockKey = 'actions:lock:' . hash('sha256', "{$user->id}:{$action->id}:{$idempotencyKey}");

            return Cache::lock($lockKey, 120)->block(30, fn () => $this->runOnce($user, $action, $validated, $idempotencyKey));
        }

        return $this->runOnce($user, $action, $validated, null);
    }

    /**
     * Records a receipt for work an endpoint already did, so it can offer the
     * same Undo as a registry command. The retry of an idempotent request gets
     * the first receipt back.
     *
     * @param  array<string, mixed>  $target
     */
    public function record(User $user, ActionDefinition $action, array $target, ActionOutcome $outcome, ?string $idempotencyKey = null): ActionReceipt
    {
        if ($idempotencyKey === null) {
            return $this->write($user, $action, $target, null, fn (): ActionOutcome => $outcome);
        }

        $lockKey = 'actions:lock:' . hash('sha256', "{$user->id}:{$action->id}:{$idempotencyKey}");

        return Cache::lock($lockKey, 120)->block(30, fn () => $this->write($user, $action, $target, $idempotencyKey, fn (): ActionOutcome => $outcome));
    }

    /** The receipt a keyed run of this action already wrote, if any. */
    public function replay(User $user, ActionDefinition $action, string $idempotencyKey): ?ActionReceipt
    {
        $previous = Cache::get("actions:run:{$user->id}:{$action->id}:{$idempotencyKey}");

        return is_string($previous) ? ActionReceipt::find($user, $previous) : null;
    }

    /** @throws ActionFailed */
    public function undo(User $user, string $receiptId): ActionReceipt
    {
        $lockKey = 'actions:undo:' . hash('sha256', "{$user->id}:{$receiptId}");

        return Cache::lock($lockKey, 120)->block(30, fn () => $this->undoOnce($user, $receiptId));
    }

    /** @param array<string, mixed> $validated */
    private function runOnce(User $user, ActionDefinition $action, array $validated, ?string $idempotencyKey): ActionReceipt
    {
        $target = [...Arr::only($validated, array_keys($action->target)), ...$action->params];

        return $this->write($user, $action, $target, $idempotencyKey, fn (): ActionOutcome => app($action->handler)->handle($user, $target));
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  callable(): ActionOutcome  $work
     */
    private function write(User $user, ActionDefinition $action, array $target, ?string $idempotencyKey, callable $work): ActionReceipt
    {
        $cacheKey = $idempotencyKey === null ? null : "actions:run:{$user->id}:{$action->id}:{$idempotencyKey}";
        if ($cacheKey !== null && is_string($previous = Cache::get($cacheKey))) {
            if ($receipt = ActionReceipt::find($user, $previous)) {
                return $receipt;
            }
        }

        $receiptId = (string) Str::uuid();

        $progress = ActionProgress::createProgress(
            userId: (string) $user->id,
            actionType: ActionReceipt::ACTION_TYPE,
            actionId: $receiptId,
            step: ActionStatus::Running->value,
            message: $action->title,
            details: ['command' => [
                'action_id' => $action->id,
                'status' => ActionStatus::Running->value,
                'confirm' => $action->confirm->value,
                'target' => $target,
            ]],
        );

        if ($cacheKey !== null) {
            Cache::put($cacheKey, $receiptId, now()->addHours(self::IDEMPOTENCY_HOURS));
        }

        try {
            $outcome = $work();
            $this->finish($progress, $outcome->status, $outcome->message, [
                'result' => $outcome->result,
                'undo' => $outcome->undo === null ? null : [
                    'payload' => $outcome->undo,
                    'expires_at' => now()->addMinutes(self::UNDO_WINDOW_MINUTES)->toIso8601String(),
                ],
            ]);
        } catch (ActionFailed $e) {
            $this->fail($progress, $e->getMessage(), $e->failureCode);
        } catch (Throwable $e) {
            report($e);
            $this->fail($progress, 'Something went wrong.', 'error');
        }

        return new ActionReceipt($progress->fresh());
    }

    private function undoOnce(User $user, string $receiptId): ActionReceipt
    {
        $receipt = ActionReceipt::find($user, $receiptId) ?? throw ActionFailed::notFound('Receipt');

        if ($receipt->isUndone()) {
            return $receipt;
        }

        $undo = $receipt->undoPayload();
        $action = app(ActionRegistry::class)->find($receipt->actionId());
        $handler = $action?->handler === null ? null : app($action->handler);

        if ($undo === null || ! $handler instanceof Reversible || $receipt->status() !== ActionStatus::Succeeded) {
            throw new ActionFailed('Nothing to undo.', 'not_undoable');
        }

        if ($receipt->undoExpired()) {
            throw new ActionFailed('Undo has expired.', 'undo_expired');
        }

        $handler->undo($user, $undo['payload']);

        $receipt->progress->update([
            'message' => "Undone: {$action->title}",
            'details' => $this->mergeCommand($receipt->progress, ['undone_at' => now()->toIso8601String()]),
        ]);

        return new ActionReceipt($receipt->progress->fresh());
    }

    /** @param  array<string, mixed>  $command */
    private function finish(ActionProgress $progress, ActionStatus $status, string $message, array $command): void
    {
        $progress->update([
            'step' => $status->value,
            'message' => $message,
            'progress' => $progress->total,
            'completed_at' => now(),
            'details' => $this->mergeCommand($progress, ['status' => $status->value, ...$command]),
        ]);
    }

    private function fail(ActionProgress $progress, string $message, string $code): void
    {
        $progress->update([
            'step' => ActionStatus::Failed->value,
            'message' => $message,
            'failed_at' => now(),
            'error_message' => $message,
            'details' => $this->mergeCommand($progress, ['status' => ActionStatus::Failed->value, 'error_code' => $code]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array<string, mixed>
     */
    private function mergeCommand(ActionProgress $progress, array $command): array
    {
        $details = $progress->details ?? [];
        $details['command'] = [...($details['command'] ?? []), ...$command];

        return $details;
    }
}
