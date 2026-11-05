<?php

namespace App\Spotlight\Queries\Scoped;

use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;

class EventActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on event pages.
     * Offers what the ActionRegistry lists for the event page, plus delete.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('event', fn (string $query, $token) => ContextualActionsQuery::results('events.show', ['event' => $token->getParameter('id')], $query)
            ->merge(ContextualActionsQuery::trashCommands('events.show', $query)));
    }
}
