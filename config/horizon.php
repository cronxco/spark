<?php

use Illuminate\Support\Str;

return [
    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env(
        'HORIZON_PREFIX',
        Str::slug(env('APP_NAME', 'laravel'), '_') . '_horizon:'
    ),

    'middleware' => ['web', 'auth', 'verified'],

    // Emails allowed to view Horizon dashboard in non-local environments
    'allowed_emails' => array_filter(array_map('trim', explode(',', env('HORIZON_ALLOWED_EMAILS', '')))),

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'environments' => [
        'production' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['pull'],
                'balance' => 'auto',
                'maxProcesses' => 5,
                'memory' => 256,
                'tries' => 3,
            ],
            'supervisor-2' => [
                'connection' => 'redis',
                'queue' => ['migration'],
                'balance' => 'auto',
                'maxProcesses' => 1,
                'memory' => 256,
                'tries' => 1,
            ],
        ],

        'staging' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['pull'],
                'balance' => 'auto',
                'maxProcesses' => 5,
                'memory' => 256,
                'tries' => 3,
            ],
            'supervisor-2' => [
                'connection' => 'redis',
                'queue' => ['migration'],
                'balance' => 'auto',
                'maxProcesses' => 1,
                'memory' => 256,
                'tries' => 1,
            ],
        ],

        'local' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['pull'],
                'balance' => 'simple',
                'maxProcesses' => 5,
                'memory' => 256,
                'tries' => 3,
            ],
            'supervisor-2' => [
                'connection' => 'redis',
                'queue' => ['migration'],
                'balance' => 'auto',
                'maxProcesses' => 1,
                'memory' => 256,
                'tries' => 1,
            ],
        ],
    ],
];
