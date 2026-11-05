<?php

namespace App\Spotlight\Queries\Scoped;

use App\Spotlight\Queries\Actions\ContextualActionsQuery;
use WireElements\Pro\Components\Spotlight\SpotlightQuery;

class MetricActionsQuery
{
    /**
     * Create Spotlight query for context-aware actions on metric pages.
     * Offers what the ActionRegistry lists for the metric page.
     */
    public static function make(): SpotlightQuery
    {
        return SpotlightQuery::forToken('metric', fn (string $query, $token) => ContextualActionsQuery::results('metrics.show', ['metric' => $token->getParameter('id')], $query));
    }
}
