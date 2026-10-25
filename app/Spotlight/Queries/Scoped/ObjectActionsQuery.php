<?php

namespace App\Spotlight\Queries\Scoped;

use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;

class ObjectActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on object pages.
     * Offers what the ActionRegistry lists for the object page, plus delete.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('object', fn (string $query, $token) => ContextualActionsQuery::results('objects.show', ['object' => $token->getParameter('id')], $query)
            ->merge(ContextualActionsQuery::trashCommands('objects.show', $query)));
    }
}
