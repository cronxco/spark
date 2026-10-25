<?php

namespace App\Services\Actions;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which registry actions a page offers. An action lists the routes it belongs
 * to and which route parameter supplies each target field; this resolves that
 * for the page the user is on, and drops what does not apply to the record
 * (Pause on a paused integration, anything on a record the user does not own).
 */
final class ContextualActions
{
    public function __construct(private ActionRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $parameters  the route's parameters, models or raw ids
     * @return Collection<int, array{action: ActionDefinition, target: array<string, string>}>
     */
    public function for(User $user, string $route, array $parameters): Collection
    {
        return $this->registry->forSurface('web')
            ->filter(fn (ActionDefinition $action): bool => isset($action->contexts[$route]))
            ->map(fn (ActionDefinition $action): ?array => $this->resolve($user, $action, $action->contexts[$route], $parameters))
            ->filter()
            ->values();
    }

    /**
     * @param  array<string, string>  $map
     * @param  array<string, mixed>  $parameters
     * @return array{action: ActionDefinition, target: array<string, string>}|null
     */
    private function resolve(User $user, ActionDefinition $action, array $map, array $parameters): ?array
    {
        $target = [];

        foreach ($map as $field => $parameter) {
            $value = $parameters[$parameter] ?? null;
            $value = is_object($value) && method_exists($value, 'getKey') ? $value->getKey() : $value;

            if (! is_string($value) || $value === '') {
                return null;
            }

            $target[$field] = $value;
        }

        $handler = $action->handler === null ? null : app($action->handler);

        if ($handler instanceof Applicable && ! $handler->applies($user, [...$target, ...$action->params])) {
            return null;
        }

        return ['action' => $action, 'target' => $target];
    }
}
