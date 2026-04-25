<?php

use App\Models\PushSubscription;

return [
    /*
     * VAPID keys are used for authentication with Web Push services.
     * Generate these keys using: php artisan webpush:vapid
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:support@example.com'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
     * The model to use for push subscriptions.
     */
    'model' => PushSubscription::class,

    /*
     * GCM Sender ID (optional, for legacy Chrome support)
     */
    'gcm' => [
        'key' => env('GCM_KEY'),
        'sender_id' => env('GCM_SENDER_ID'),
    ],

    /*
     * Default options for push messages
     */
    'defaults' => [
        'TTL' => 2419200, // 4 weeks in seconds
        'urgency' => 'normal', // low, normal, high
        'topic' => null,
        'batchSize' => 1000,
    ],
];
