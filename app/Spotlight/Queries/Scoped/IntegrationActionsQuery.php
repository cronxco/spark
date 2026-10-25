<?php

namespace App\Spotlight\Queries\Scoped;

use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;

class IntegrationActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on integration pages.
     * Offers what the ActionRegistry lists for the integration page.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('integration', fn (string $query, $token) => ContextualActionsQuery::results('integrations.details', ['integration' => $token->getParameter('id')], $query));
    }
}
