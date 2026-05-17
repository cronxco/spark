<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Fetch Debug
    |--------------------------------------------------------------------------
    |
    | When enabled, the Playwright and content-extraction clients write the
    | full fetched HTML, extracted text and screenshots to disk for debugging.
    | Files are namespaced per-user under storage/logs/fetch/{user_id}/ so
    | they never leak across users. Keep this disabled in production.
    |
    */

    'debug' => env('FETCH_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | URL Safety (SSRF protection)
    |--------------------------------------------------------------------------
    |
    | User-supplied URLs are validated before Spark fetches them server-side.
    | Private, loopback, link-local and reserved IP ranges are blocked, DNS is
    | resolved and every resolved IP is checked, and redirects are re-validated.
    | "allowed_hosts" is an exact-match allowlist for trusted internal hosts
    | that should bypass the IP checks (use sparingly).
    |
    */

    'url_safety' => [
        'allowed_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FETCH_URL_SAFETY_ALLOWED_HOSTS', ''))
        ))),
        'max_redirects' => env('FETCH_URL_SAFETY_MAX_REDIRECTS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Paywall Detection
    |--------------------------------------------------------------------------
    |
    | Paywall detection is intentionally conservative to avoid false positives
    | on pages that load fine in the browser. Domains listed here never get
    | flagged as paywalled.
    |
    */

    'paywall' => [
        'ignored_domains' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('FETCH_PAYWALL_IGNORED_DOMAINS', ''))
        ))),
        'min_strong_indicators' => env('FETCH_PAYWALL_MIN_STRONG_INDICATORS', 2),
        'max_content_length' => env('FETCH_PAYWALL_MAX_CONTENT_LENGTH', 600),
    ],

];
