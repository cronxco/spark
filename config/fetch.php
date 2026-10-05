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

    /*
    |--------------------------------------------------------------------------
    | List Page Detection
    |--------------------------------------------------------------------------
    |
    | Pages (and newsletter emails) that are really lists of articles are
    | detected with deterministic link clustering plus a batch of typed Jev
    | questions. Thresholds are per question and tuned for the pinned Jev model
    | in services.jev.model; re-tune them when the model changes. In shadow
    | mode assessments are recorded on the bookmark but never acted on.
    |
    */

    'list_detection' => [
        'enabled' => env('FETCH_LIST_DETECTION_ENABLED', false),
        'shadow' => env('FETCH_LIST_DETECTION_SHADOW', true),
        'min_cluster_items' => env('FETCH_LIST_MIN_CLUSTER_ITEMS', 5),
        'max_clusters' => 6,
        'max_links' => 60,
        'max_newsletter_links' => 40,
        'budget_seconds' => 3.0,
        'negative_memo_days' => 14,
        'thresholds' => [
            'page_kind_list' => 0.6,
            'page_kind_single_article_max' => 0.3,
            'has_article_list' => 0.6,
            'cluster_is_primary' => 0.6,
            'link_is_article' => 0.6,
            'newsletter_link_digest' => 0.6,
            'newsletter_link_is_article' => 0.6,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | List Expansion
    |--------------------------------------------------------------------------
    |
    | How newly found list items become bookmarks. The first scan of a list
    | records every item as seen and only fetches the top "initial_backfill".
    | Later scans fetch at most "max_new_per_run" genuinely new items; the rest
    | stay eligible for the next run. "tracking_params" are stripped when
    | computing a URL's dedupe identity (never from the URL that is fetched).
    |
    */

    'list_expansion' => [
        'initial_backfill' => env('FETCH_LIST_INITIAL_BACKFILL', 5),
        'max_new_per_run' => env('FETCH_LIST_MAX_NEW_PER_RUN', 20),
        'stagger_seconds' => 20,
        'dispatch_guard_minutes' => 30,
        'tracking_params' => [
            'utm_*', 'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', '_hsenc', '_hsmi',
            'mkt_tok', 'ref_src', 'igshid', 'vero_id', 'oly_enc_id', 'oly_anon_id', '__s',
        ],
    ],

];
