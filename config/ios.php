<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Apple App Site Association (AASA)
    |--------------------------------------------------------------------------
    |
    | Paths whitelisted for Universal Links. Each entry maps directly into the
    | AASA JSON served at /.well-known/apple-app-site-association.
    |
    */

    'bundle_id' => 'co.cronx.sparkapp',

    'aasa_paths' => [
        '/events/*',
        '/objects/*',
        '/places/*',
        '/tasks/*',
        '/projects/*',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile API Feature Flag
    |--------------------------------------------------------------------------
    |
    | Gates the entire /api/v1/mobile/* surface. Disabled in production until
    | the iOS companion client is ready to ship. Requests hit a 404 when
    | disabled so the endpoints are indistinguishable from "not deployed".
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Quick Log Intents
    |--------------------------------------------------------------------------
    |
    | Below this on-device confidence the quick log asks which intent was
    | meant before saving anything. Tune against real override rates.
    |
    */

    'quick_log' => [
        'confidence_threshold' => (float) env('IOS_QUICK_LOG_CONFIDENCE_THRESHOLD', 0.7),
    ],

    'mobile_api_enabled' => env('IOS_MOBILE_API_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Apple Team ID
    |--------------------------------------------------------------------------
    |
    | Injected into the AASA (apple-app-site-association) manifest so iOS
    | universal links resolve to the Spark app. Must match the Team ID on the
    | provisioning profile; empty in development so the manifest just returns
    | an empty appID.
    |
    */

    'apple_team_id' => env('APPLE_TEAM_ID', ''),

    'app_bundle_id' => env('APN_BUNDLE_ID', 'co.cronx.sparkapp'),
];
