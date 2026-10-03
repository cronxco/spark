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

];
