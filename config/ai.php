<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Providers
    |--------------------------------------------------------------------------
    |
    | Every AI call in Spark goes through laravel/ai. Text, embeddings and
    | structured output use OpenAI; typed classification uses TypeSafe's Jev.
    | Models are not chosen here: each call names its model through
    | App\Services\Ai\AiModel (services.openai.models) or services.jev.model.
    |
    */

    'default' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_classification' => 'typesafe',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | App\Services\Ai\EmbeddingClient caches embeddings itself, keyed by model
    | and dimensions, so the SDK's own embedding cache stays off.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | OpenAI requests are sent with `store` off: nothing Spark sends is kept
    | on OpenAI's side, so every tool-calling step carries its full history.
    |
    */

    'providers' => [
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', false),
            'headers' => array_filter([
                'OpenAI-Organization' => env('OPENAI_ORGANIZATION'),
            ]),
        ],

        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
            'url' => rtrim(env('JEV_BASE_URL', 'https://api.typesafe.ai'), '/') . '/v1',
        ],
    ],

];
