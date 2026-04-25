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

    'bundle_id' => 'co.cronx.spark',

    'aasa_paths' => [
        '/event/*',
        '/today',
        '/day/*',
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

    'app_bundle_id' => env('APN_BUNDLE_ID', 'co.cronx.spark'),
];
