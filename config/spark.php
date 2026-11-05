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
    | Admin God Mode
    |--------------------------------------------------------------------------
    |
    | Admin pages are scoped to the signed-in admin. A global operator (an
    | admin whose user id is listed here) can switch on God Mode, which shows
    | everything across all users for a limited time after a fresh Authelia
    | sign-in. Every step is written to the security activity log.
    |
    */

    'admin' => [
        'global_operators' => array_values(array_filter(array_map('trim', explode(',', (string) env('SPARK_GLOBAL_OPERATORS', ''))))),
        'god_mode_minutes' => (int) env('SPARK_GOD_MODE_MINUTES', 30),
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
