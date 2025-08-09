<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'authelia' => [
        'client_id' => env('AUTHELIA_CLIENT_ID'),
        'client_secret' => env('AUTHELIA_CLIENT_SECRET'),
        'redirect' => env('AUTHELIA_REDIRECT_URI'),
        'base_url' => env('AUTHELIA_BASE_URL'),
        'token_endpoint_auth_method' => 'client_secret_basic',
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => env('GITHUB_REDIRECT_URI'),
    ],

    'spotify' => [
        'client_id' => env('SPOTIFY_CLIENT_ID'),
        'client_secret' => env('SPOTIFY_CLIENT_SECRET'),
        'redirect' => env('SPOTIFY_REDIRECT_URI'),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),
        'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),
        'environment' => env('APP_ENV', 'production'),
        'release' => env('SENTRY_RELEASE'),
    ],

];
