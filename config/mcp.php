<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MCP Sentry Tracing
    |--------------------------------------------------------------------------
    |
    | Enable Sentry tracing for Model Context Protocol (MCP) server operations.
    | This includes tracing for tool calls, resource reads, and initialization.
    |
    */

    'sentry' => [
        'enabled' => env('SENTRY_MCP_TRACING_ENABLED', true),
        'sample_rate' => (float) env('SENTRY_MCP_SAMPLE_RATE', 0.1),
    ],
];
