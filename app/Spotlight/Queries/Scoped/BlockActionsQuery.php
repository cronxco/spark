<?php

namespace App\Spotlight\Queries\Scoped;

use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;

class BlockActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on block pages.
     * Offers what the ActionRegistry lists for the block page, plus delete.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('block', fn (string $query, $token) => ContextualActionsQuery::results('blocks.show', ['block' => $token->getParameter('id')], $query)
            ->merge(ContextualActionsQuery::trashCommands('blocks.show', $query)));
    }
}
