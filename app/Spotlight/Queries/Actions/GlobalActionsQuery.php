<?php

namespace App\Spotlight\Queries\Actions;

use App\Services\Actions\ActionDefinition;
use App\Services\Actions\ActionRegistry;
use Illuminate\Support\Collection;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;
use WireElements\Pro\Components\Spotlight\SpotlightResult;

class GlobalActionsQuery
{
    /**
     * Create Spotlight query for global actions (triggered by ">" mode).
     * Renders from the ActionRegistry; nothing is declared here.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forMode('actions', function (string $query) {
            return self::visible()
                ->filter(fn (ActionDefinition $action): bool => $action->matches($query))
                ->map(function (ActionDefinition $action): SpotlightResult {
                    $result = SpotlightResult::make()
                        ->setTitle($action->title)
                        ->setSubtitle($action->subtitle)
                        ->setTypeahead('Action: ' . $action->title)
                        ->setIcon($action->icon)
                        ->setGroup('commands')
                        ->setPriority(1);

                    return $action->isNavigation()
                        ? $result->setAction('jump_to', ['path' => route($action->destination['route'])])
                        : $result->setAction('dispatch_event', [
                            'name' => 'run-action',
                            'data' => ['action' => $action->id],
                            'close' => true,
                        ]);
                })
                ->values();
        });
    }

    /**
     * What ">" mode offers: web actions that run with no input and no confirm
     * step, except the ones that belong to a page. Each non-navigation one is
     * run by the `run-action` listener mounted in the app layout.
     *
     * @return Collection<int, ActionDefinition>
     */
    public static function visible(): Collection
    {
        return app(ActionRegistry::class)->forSurface('web')
            ->filter(fn (ActionDefinition $action): bool => $action->isNavigation() || ($action->runsWithoutInput() && ! $action->isContextual()))
            ->values();
    }
}
