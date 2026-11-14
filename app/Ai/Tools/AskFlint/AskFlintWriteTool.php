<?php

namespace App\Ai\Tools\AskFlint;

use App\Models\User;
use App\Services\Actions\ActionDefinition;
use App\Services\Actions\ActionExecutor;
use App\Services\Actions\ActionRegistry;
use App\Services\Actions\ActionStatus;
use App\Services\Api\EntityMutationService;
use App\Services\Flint\Ask\AskFlintChangeLog;
use App\Support\SparkAbility;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use LogicException;
use Throwable;

/**
 * A change Ask Flint may make to the user's data.
 *
 * Every write tool applies its change at once and records an action receipt
 * that undoes it, on the same contract as a registry command, so the app's
 * Undo works the same for a change Flint made as for one the user made. A
 * failure is returned to the model as text rather than thrown: it should be
 * able to tell the user what went wrong.
 */
abstract class AskFlintWriteTool implements Tool
{
    public const CAPABILITY = 'data:write';

    public function __construct(
        protected User $user,
        protected AskFlintChangeLog $changes,
        protected EntityMutationService $mutations,
        protected ActionExecutor $executor,
        protected ActionRegistry $registry,
    ) {}

    abstract public function name(): string;

    public function handle(Request $request): string
    {
        if (! SparkAbility::allows($this->user, self::CAPABILITY)) {
            return $this->failed('This session is read-only, so nothing was changed.');
        }

        if ($this->changes->limitReached()) {
            return $this->failed('Change limit for one reply reached. Nothing more was changed; tell the user what is left.');
        }

        try {
            return json_encode($this->apply($request), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (ValidationException $exception) {
            return $this->failed($exception->validator->errors()->first());
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed('The change could not be made.');
        }
    }

    /**
     * Apply the change and return what to tell the model.
     *
     * @return array<string, mixed>
     */
    abstract protected function apply(Request $request): array;

    protected function action(string $id): ActionDefinition
    {
        return $this->registry->find($id) ?? throw new LogicException("Action {$id} is not registered.");
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    protected function done(array $change): array
    {
        return ['status' => 'changed', 'change' => $change, 'note' => 'Applied. The app shows the user an Undo for this change.'];
    }

    /** @return array<string, mixed> */
    protected function notFound(string $what): array
    {
        return ['status' => 'failed', 'error' => "{$what} not found, or it is not the user's."];
    }

    /** @return array<string, mixed> */
    protected function receiptFailed(ActionStatus $status, ?string $message): array
    {
        return ['status' => 'failed', 'error' => $message ?: "The change ended as {$status->value}."];
    }

    private function failed(string $message): string
    {
        return json_encode(['status' => 'failed', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
