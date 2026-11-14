<?php

namespace App\Ai\Tools\AskFlint;

use App\Services\Actions\ActionDefinition;
use App\Services\Actions\ActionKind;
use App\Services\Actions\ActionStatus;
use App\Services\Actions\ConfirmationRequired;
use App\Services\Actions\ConfirmPolicy;
use App\Support\SparkAbility;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;

/**
 * Runs a command from the action registry, the same one the app's command
 * surfaces run: sync an integration, pause one, acknowledge an anomaly.
 *
 * Flint is offered only what the native app could run without asking first.
 * An action that needs confirmation is left to the app, where the user can
 * be asked; navigation and web-only actions have nothing for Flint to run;
 * and event changes go through their own tools, which say more about what
 * changed. The user's token must hold each action's own capability.
 */
class RunActionTool extends AskFlintWriteTool
{
    /** Registry domains whose actions Flint reaches through its other tools. */
    private const OWN_TOOL_PREFIXES = ['events.'];

    public function name(): string
    {
        return 'run-action';
    }

    public function description(): string
    {
        $actions = $this->runnable()
            ->map(function (ActionDefinition $action): string {
                $needs = array_keys($action->target);

                return "- {$action->id}: {$action->title}. {$action->subtitle}" . ($needs === [] ? '' : ' Needs ' . implode(', ', $needs) . '.');
            })
            ->implode("\n");

        return "Run one of Spark's commands when the user asks for it. Runs immediately. Look up any id it needs first; never guess one.\n{$actions}";
    }

    public function schema(JsonSchema $schema): array
    {
        $runnable = $this->runnable();

        $fields = $runnable
            ->flatMap(fn (ActionDefinition $action): array => collect($action->target)
                ->map(fn (array $field, string $name): array => ['name' => $name, 'type' => $field['type'], 'action' => $action->id])
                ->values()
                ->all())
            ->groupBy('name')
            ->map(function (Collection $uses, string $name) use ($schema) {
                $type = $uses->first()['type'] === 'integer' ? $schema->integer() : $schema->string();

                return $type->description('For ' . $uses->pluck('action')->implode(', ') . '.');
            })
            ->all();

        return [
            'action' => $schema->string()->enum($runnable->map->id->values()->all())->description('The command to run.')->required(),
            ...$fields,
        ];
    }

    protected function apply(Request $request): array
    {
        $action = $this->runnable()->first(fn (ActionDefinition $candidate): bool => $candidate->id === $request['action']);

        if ($action === null) {
            return ['status' => 'failed', 'error' => 'That is not a command Flint can run. If it needs confirming, the user can run it in the app.'];
        }

        $target = collect($request->all())->only(array_keys($action->target))->all();

        try {
            $receipt = $this->executor->run($this->user, $action, $target, idempotencyKey: (string) Str::uuid());
        } catch (ConfirmationRequired) {
            return ['status' => 'failed', 'error' => 'That command asks the user to confirm first, so Flint cannot run it. They can run it in the app.'];
        }

        if ($receipt->status() !== ActionStatus::Succeeded) {
            return $this->receiptFailed($receipt->status(), $receipt->progress->error_message);
        }

        $summary = (string) ($receipt->progress->message ?: $action->title);

        if (! $receipt->canUndo()) {
            return ['status' => 'ran', 'action' => $action->id, 'summary' => $summary];
        }

        return $this->done($this->changes->addAction($receipt, $action, $summary));
    }

    /**
     * The commands this user's token may run through Flint.
     *
     * @return Collection<int, ActionDefinition>
     */
    private function runnable(): Collection
    {
        return $this->registry->forSurface('native')
            ->filter(fn (ActionDefinition $action): bool => $action->handler !== null
                && in_array($action->kind, [ActionKind::Safe, ActionKind::Data], true)
                && $action->confirm !== ConfirmPolicy::Confirm
                && ! Str::startsWith($action->id, self::OWN_TOOL_PREFIXES)
                && ($action->capability === null || SparkAbility::allows($this->user, $action->capability)))
            ->values();
    }
}
