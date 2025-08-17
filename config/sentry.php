<?php

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'environment' => env('APP_ENV', 'production'),

    'release' => env('SENTRY_RELEASE'),

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),

    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    'send_default_pii' => false,

    'breadcrumbs' => [
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
    ],

    // Continue incoming distributed traces from the browser (sentry-trace + baggage)
    'tracing' => [
        'queue_job_transactions' => true,
        'queue_job_transactions_trim_uuids' => true,
    ],
];
