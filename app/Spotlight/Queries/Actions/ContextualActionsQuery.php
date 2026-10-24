<?php

namespace App\Spotlight\Queries\Actions;

use App\Services\Actions\ActionDefinition;
use App\Services\Actions\ContextualActions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class ContextualActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions.
     * Offers what the ActionRegistry lists for the current route.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::asDefault(function (string $query) {
            $route = request()->route();
            if (! $route) {
                return collect();
            }

            $routeName = $route->getName();

            return self::results($routeName, $route->parameters(), $query)
                ->merge(self::trashCommands($routeName, $query));
        });
    }

    /**
     * Registry actions for a page, as Spotlight results. The per-record token
     * queries call this too, with the route the token stands for.
     *
     * @param  array<string, mixed>  $parameters
     * @return Collection<int, SpotlightResult>
     */
    public static function results(?string $route, array $parameters, string $query): Collection
    {
        $user = Auth::user();

        if ($route === null || $user === null) {
            return collect();
        }

        return app(ContextualActions::class)->for($user, $route, $parameters)
            ->filter(fn (array $contextual): bool => $contextual['action']->matches($query))
            ->values()
            ->map(fn (array $contextual, int $position): SpotlightResult => self::result($contextual['action'], $contextual['target'], $position + 1));
    }

    /**
     * The delete commands still run on the page itself. They need a confirm
     * step and a receipt on web, which the registry does not have yet.
     *
     * @return Collection<int, SpotlightResult>
     */
    public static function trashCommands(?string $route, string $query): Collection
    {
        $results = collect();

        if ($route === 'admin.bin.index' && (blank($query) || str_contains(strtolower($query), 'clear') || str_contains(strtolower($query), 'delete'))) {
            $results->push(
                SpotlightResult::make()
                    ->setTitle('Clear Bin (Destructive)')
                    ->setSubtitle('Permanently delete all items in the bin')
                    ->setIcon('trash')
                    ->setGroup('commands')
                    ->setPriority(1)
                    ->setAction('clear_bin', [])
            );
        }

        $page = ['events.show' => ['Event', 'delete-event'], 'objects.show' => ['Object', 'delete-object'], 'blocks.show' => ['Block', 'delete-block']][$route] ?? null;

        if ($page !== null && (blank($query) || str_contains(strtolower($query), 'delete'))) {
            $results->push(
                SpotlightResult::make()
                    ->setTitle("Delete {$page[0]}")
                    ->setSubtitle('Move this ' . strtolower($page[0]) . ' to the bin')
                    ->setIcon('trash')
                    ->setGroup('commands')
                    ->setPriority(4)
                    ->setAction('dispatch_event', [
                        'name' => $page[1],
                        'data' => [],
                        'close' => true,
                    ])
            );
        }

        return $results;
    }

    /**
     * The browser event a command fires: the page's own event for something it
     * opens, or `run-action` for something the server runs.
     *
     * @param  array<string, string>  $target
     * @return array{name: string, data: array<string, mixed>, close: bool}
     */
    public static function dispatch(ActionDefinition $action, array $target): array
    {
        return $action->isInterface()
            ? ['name' => $action->event, 'data' => [], 'close' => true]
            : ['name' => 'run-action', 'data' => ['action' => $action->id, 'target' => $target], 'close' => true];
    }

    /** @param  array<string, string>  $target */
    private static function result(ActionDefinition $action, array $target, int $priority): SpotlightResult
    {
        return SpotlightResult::make()
            ->setTitle($action->title)
            ->setSubtitle($action->subtitle)
            ->setIcon($action->icon)
            ->setGroup('commands')
            ->setPriority($priority)
            ->setAction('dispatch_event', self::dispatch($action, $target));
    }
}
