<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Flint Assistant Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Flint AI assistant integration.
    |
    */

    'assistant' => [
        'max_events' => env('ASSISTANT_MAX_EVENTS', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin operator access
    |--------------------------------------------------------------------------
    |
    | Admin pages are scoped to the signed-in admin. A global operator (an
    | admin whose user id is listed here) can view one other user's data for
    | a limited time, with a reason and a fresh password confirmation, and
    | every step is written to the security activity log (decision D-API-2).
    |
    */

    'admin' => [
        'global_operators' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPARK_GLOBAL_OPERATORS', ''))))),
        'operator_context_minutes' => (int) env('SPARK_OPERATOR_CONTEXT_MINUTES', 30),
        'operator_reauth_minutes' => 15,
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Ranking
    |--------------------------------------------------------------------------
    |
    | Recency is one signal in the ranking shared by Spotlight and the mobile
    | search API. A result's score blends how well it matches with how recent
    | it is: score = (1 - weight) * relevance + weight * recency, where recency
    | halves every `half_life_days`. A weight of 0 turns the signal off and
    | ranks by relevance alone. Compare settings with `search:recency-check`.
    |
    */

    'search' => [
        'recency' => [
            'weight' => (float) env('SEARCH_RECENCY_WEIGHT', 0.2),
            'half_life_days' => (float) env('SEARCH_RECENCY_HALF_LIFE_DAYS', 30),
        ],

    ],

];
