<?php

namespace App\Services\Actions;

use Illuminate\Support\Collection;
use LogicException;

/**
 * Every command the app offers. Spotlight (web) and the native Actions
 * endpoint render from here, so a command exists in one place with one
 * owner, capability and confirm policy.
 */
final class ActionRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $actions = [];

    /** @param  iterable<ActionDefinition>  $definitions */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }

    public function register(ActionDefinition $definition): void
    {
        if (isset($this->actions[$definition->id])) {
            throw new LogicException("Action {$definition->id} is registered twice.");
        }

        $this->actions[$definition->id] = $definition;
    }

    /** @return Collection<string, ActionDefinition> */
    public function all(): Collection
    {
        return collect($this->actions);
    }

    public function find(string $id): ?ActionDefinition
    {
        return $this->actions[$id] ?? null;
    }

    /** @return Collection<string, ActionDefinition> */
    public function forSurface(string $surface): Collection
    {
        return $this->all()->filter(fn (ActionDefinition $action): bool => $action->on($surface));
    }
}
