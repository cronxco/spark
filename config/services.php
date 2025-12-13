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

    'reddit' => [
        'client_id' => env('REDDIT_CLIENT_ID'),
        'client_secret' => env('REDDIT_CLIENT_SECRET'),
        'redirect' => env('REDDIT_REDIRECT_URI'),
        'useragent' => env('REDDIT_USERAGENT', 'SparkApp/1.0 by u/example'),
    ],

    'monzo' => [
        'client_id' => env('MONZO_CLIENT_ID'),
        'client_secret' => env('MONZO_CLIENT_SECRET'),
        'redirect' => env('MONZO_REDIRECT_URI'),
        'salary_name' => env('MONZO_SALARY_NAME'),
    ],

    'gocardless' => [
        // GoCardless Bank Account Data API credentials
        'secret_id' => env('GOCARDLESS_SECRET_ID'),
        'secret_key' => env('GOCARDLESS_SECRET_KEY'),
        // API base URL
        'api_base' => env('GOCARDLESS_API_BASE', 'https://bankaccountdata.gocardless.com/api/v2'),
        // Optional: limit to a country or pre-select an institution
        'country' => env('GOCARDLESS_COUNTRY', 'GB'),
        'institution_id' => env('GOCARDLESS_INSTITUTION_ID'),
        // Redirect URI for requisition flow
        'redirect' => env('GOCARDLESS_REDIRECT_URI', env('APP_URL') . '/integrations/gocardless/callback'),
    ],

    'oura' => [
        'client_id' => env('OURA_CLIENT_ID'),
        'client_secret' => env('OURA_CLIENT_SECRET'),
        'redirect' => env('OURA_REDIRECT_URI'),
    ],

    'hevy' => [
        'api_key' => env('HEVY_API_KEY'),
    ],

    'sentry' => [
        'dsn' => env('SENTRY_LARAVEL_DSN'),
        'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.2),
        'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.0),
        'environment' => env('APP_ENV', 'production'),
        'release' => env('SENTRY_RELEASE'),
    ],

    'outline' => [
        'url' => env('OUTLINE_URL'),
        'access_token' => env('OUTLINE_ACCESS_TOKEN'),
        'daynotes_collection_id' => env('OUTLINE_DAYNOTES_COLLECTION_ID', '5622670a-e725-437d-b747-a17905038df8'),
        'poll_interval_minutes' => env('OUTLINE_POLL_INTERVAL_MINUTES', 15),
    ],

    'karakeep' => [
        'url' => env('KARAKEEP_URL'),
        'access_token' => env('KARAKEEP_ACCESS_TOKEN'),
    ],

    'google-calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    ],

    'bluesky' => [
        'oauth_private_key' => env('BLUESKY_OAUTH_PRIVATE_KEY'),
        'redirect' => env('BLUESKY_REDIRECT', '/bluesky/oauth/callback'),
    ],

    'playwright' => [
        'enabled' => env('PLAYWRIGHT_ENABLED', false),
        'worker_url' => env('PLAYWRIGHT_WORKER_URL', 'http://playwright-worker:3000'),
        'chrome_vnc_url' => env('CHROME_VNC_URL', 'vnc://localhost:5900'),
        'chrome_vnc_password' => env('CHROME_VNC_PASSWORD', 'spark-dev-vnc'),
        'timeout' => env('PLAYWRIGHT_TIMEOUT', 30000), // 30 seconds
        'screenshot_enabled' => env('PLAYWRIGHT_SCREENSHOT_ENABLED', true),
        'auto_escalate' => env('PLAYWRIGHT_AUTO_ESCALATE', true), // Auto-use Playwright on HTTP failures
        'js_required_domains' => env('PLAYWRIGHT_JS_DOMAINS', 'twitter.com,x.com,instagram.com,facebook.com'),

        // Stealth mode (anti-bot detection)
        'stealth_enabled' => env('PLAYWRIGHT_STEALTH_ENABLED', true),

        // Cookie auto-refresh
        'auto_update_cookies' => env('PLAYWRIGHT_AUTO_UPDATE_COOKIES', true),

        // Browser context persistence
        'context_persistence_enabled' => env('PLAYWRIGHT_CONTEXT_PERSISTENCE', true),
        'context_ttl' => env('PLAYWRIGHT_CONTEXT_TTL', 1800), // 30 minutes in seconds
        'context_ttl_minutes' => env('PLAYWRIGHT_CONTEXT_TTL', 1800) / 60, // For display

        // Use default browser context (includes extensions like cookie popup blocker)
        'use_default_context' => env('PLAYWRIGHT_USE_DEFAULT_CONTEXT', true),

        // Scheduled cookie refresh
        'cookie_refresh_enabled' => env('PLAYWRIGHT_COOKIE_REFRESH_ENABLED', true),
        'cookie_refresh_threshold_days' => env('PLAYWRIGHT_COOKIE_REFRESH_THRESHOLD', 7),
    ],

    'fetch' => [
        // Archive.is bypass for paywalled content
        'archive_bypass_enabled' => env('FETCH_ARCHIVE_BYPASS_ENABLED', true),
        'archive_bypass_timeout' => env('FETCH_ARCHIVE_BYPASS_TIMEOUT', 30), // seconds

        // Domains to exclude from archive bypass (comma-separated)
        // Some sites may not work well with archive.is or have their own bypass mechanisms
        'archive_bypass_excluded_domains' => env('FETCH_ARCHIVE_BYPASS_EXCLUDED_DOMAINS', ''),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'organization' => env('OPENAI_ORGANIZATION'),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    ],

    'receipt' => [
        'domain' => env('RECEIPT_DOMAIN', 'spark.cronx.co'),
        'email_address' => env('RECEIPT_EMAIL_ADDRESS', 'receipts@spark.cronx.co'),
        's3_bucket' => env('AWS_BUCKET_RECEIPTS', 'spark-receipts-emails'),
        'sns_topic_arn' => env('AWS_SNS_RECEIPT_TOPIC_ARN'),
        'retention_days' => 30,
        'auto_match_threshold' => 0.8,
        'review_threshold' => 0.5,
        'currency_tolerance_percent' => (float) env('RECEIPT_CURRENCY_TOLERANCE', 2.0),
    ],

    'currency' => [
        'api_provider' => env('CURRENCY_API_PROVIDER', 'exchangerate'),
        'api_key' => env('CURRENCY_API_KEY'),
        'api_url' => env('CURRENCY_API_URL', 'https://v6.exchangerate-api.com/v6'),
        'base_currency' => env('CURRENCY_BASE', 'GBP'),
        'cache_ttl_hours' => (int) env('CURRENCY_CACHE_TTL', 24),
        'fallback_enabled' => env('CURRENCY_FALLBACK_ENABLED', true),
        'supported_currencies' => ['GBP', 'USD', 'EUR', 'AUD', 'CAD', 'CHF', 'JPY', 'CNY'],
    ],

];
