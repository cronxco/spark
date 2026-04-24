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

];
