<?php

namespace App\Spotlight\Queries\Scoped;

use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;

class AccountActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on account pages.
     * Offers what the ActionRegistry lists for the account page.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('account', fn (string $query, $token) => ContextualActionsQuery::results('money.show', ['account' => $token->getParameter('id')], $query));
    }
}
