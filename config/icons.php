<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Icon Library
    |--------------------------------------------------------------------------
    |
    | The default icon library to use when rendering icons. Supported values:
    | 'fontawesome' or 'heroicons'
    |
    */
    'default_library' => 'fontawesome',

    /*
    |--------------------------------------------------------------------------
    | Heroicon to FontAwesome Mapping
    |--------------------------------------------------------------------------
    |
    | Maps Heroicon names to their FontAwesome equivalents. This is used by
    | the heroicon_to_fontawesome() helper and the migration command.
    |
    | Format: 'heroicon-name' => 'fontawesome-name'
    |
    */
    'heroicon_to_fontawesome_map' => [
        // =====================================================================
        // Navigation & UI Actions
        // =====================================================================
        'o-arrow-left' => 'fas-arrow-left',
        'o-arrow-right' => 'fas-arrow-right',
        'o-arrow-up' => 'fas-arrow-up',
        'o-arrow-down' => 'fas-arrow-down',
        'o-arrow-up-right' => 'fas-arrow-up-right-from-square',
        'o-arrow-down-left' => 'fas-download',
        'o-arrow-path' => 'fas-rotate',
        'o-arrow-path-rounded-square' => 'fas-repeat',
        'o-arrows-right-left' => 'fas-right-left',
        'o-arrow-down-tray' => 'fas-download',
        'o-arrow-up-tray' => 'fas-upload',
        'o-arrow-right-circle' => 'fas-circle-arrow-right',
        'o-arrow-up-circle' => 'fas-circle-arrow-up',
        'o-chevron-left' => 'fas-chevron-left',
        'o-chevron-right' => 'fas-chevron-right',
        'o-chevron-up' => 'fas-chevron-up',
        'o-chevron-down' => 'fas-chevron-down',

        // =====================================================================
        // Money & Financial
        // =====================================================================
        'o-banknotes' => 'fas-money-bills',
        'o-currency-pound' => 'fas-sterling-sign',
        'o-credit-card' => 'fas-credit-card',
        'o-building-library' => 'fas-building-columns',
        'o-building-storefront' => 'fas-store',
        'o-scale' => 'fas-scale-balanced',
        'o-wallet' => 'fas-wallet',

        // =====================================================================
        // Status & Feedback
        // =====================================================================
        'o-check-circle' => 'fas-circle-check',
        'o-x-circle' => 'fas-circle-xmark',
        'o-exclamation-triangle' => 'fas-triangle-exclamation',
        'o-information-circle' => 'fas-circle-info',
        'o-plus-circle' => 'fas-circle-plus',
        'o-minus-circle' => 'fas-circle-minus',
        'o-plus' => 'fas-plus',
        'o-minus' => 'fas-minus',
        'o-check' => 'fas-check',
        'o-x-mark' => 'fas-xmark',

        // =====================================================================
        // Trends & Analytics
        // =====================================================================
        'o-arrow-trending-up' => 'fas-arrow-trend-up',
        'o-arrow-trending-down' => 'fas-arrow-trend-down',
        'o-chart-bar' => 'fas-chart-simple',
        'o-chart-pie' => 'fas-chart-pie',
        'o-sparkles' => 'fas-wand-magic-sparkles',
        'o-light-bulb' => 'fas-lightbulb',
        'o-beaker' => 'fas-flask',

        // =====================================================================
        // Users & People
        // =====================================================================
        'o-user' => 'fas-user',
        'o-user-plus' => 'fas-user-plus',
        'o-user-minus' => 'fas-user-minus',
        'o-user-group' => 'fas-users',
        'o-user-circle' => 'fas-circle-user',
        'o-users' => 'fas-users',
        'o-face-smile' => 'fas-face-smile',

        // =====================================================================
        // Content & Documents
        // =====================================================================
        'o-document' => 'fas-file',
        'o-document-text' => 'fas-file-lines',
        'o-document-duplicate' => 'fas-copy',
        'o-bookmark' => 'fas-bookmark',
        'o-tag' => 'fas-tag',
        'o-hashtag' => 'fas-hashtag',
        'o-link' => 'fas-link',
        'o-photo' => 'fas-image',
        'o-list-bullet' => 'fas-list',
        'o-pencil' => 'fas-pen',
        'o-trash' => 'fas-trash',
        'o-eye' => 'fas-eye',
        'o-eye-slash' => 'fas-eye-slash',
        'o-magnifying-glass' => 'fas-magnifying-glass',
        'o-funnel' => 'fas-filter',

        // =====================================================================
        // Communication
        // =====================================================================
        'o-chat-bubble-left' => 'fas-comment',
        'o-chat-bubble-left-ellipsis' => 'fas-comment-dots',
        'o-chat-bubble-left-right' => 'fas-comments',
        'o-microphone' => 'fas-microphone',
        'o-speaker-wave' => 'fas-volume-high',
        'o-speaker-x-mark' => 'fas-volume-xmark',
        'o-bell' => 'fas-bell',
        'o-bell-slash' => 'fas-bell-slash',
        'o-envelope' => 'fas-envelope',

        // =====================================================================
        // Time & Calendar
        // =====================================================================
        'o-clock' => 'fas-clock',
        'o-calendar' => 'fas-calendar',
        'o-calendar-days' => 'fas-calendar-days',

        // =====================================================================
        // Media & Entertainment
        // =====================================================================
        'o-play' => 'fas-play',
        'o-pause' => 'fas-pause',
        'o-stop' => 'fas-stop',
        'o-musical-note' => 'fas-music',
        'o-fire' => 'fas-fire',
        'o-heart' => 'fas-heart',
        'o-star' => 'fas-star',
        'o-bolt' => 'fas-bolt',
        'o-sun' => 'fas-sun',
        'o-moon' => 'fas-moon',

        // =====================================================================
        // Technology & System
        // =====================================================================
        'o-cog' => 'fas-gear',
        'o-cog-6-tooth' => 'fas-gear',
        'o-adjustments-horizontal' => 'fas-sliders',
        'o-adjustments-vertical' => 'fas-sliders',
        'o-code-bracket' => 'fas-code',
        'o-cloud' => 'fas-cloud',
        'o-cloud-arrow-down' => 'fas-cloud-arrow-down',
        'o-cloud-arrow-up' => 'fas-cloud-arrow-up',
        'o-puzzle-piece' => 'fas-puzzle-piece',
        'o-shield-check' => 'fas-shield-halved',
        'o-lock-closed' => 'fas-lock',
        'o-lock-open' => 'fas-lock-open',
        'o-key' => 'fas-key',
        'o-computer-desktop' => 'fas-desktop',
        'o-device-phone-mobile' => 'fas-mobile-screen',
        'o-server' => 'fas-server',
        'o-cpu-chip' => 'fas-microchip',
        'o-power' => 'fas-power-off',

        // =====================================================================
        // Location & Maps
        // =====================================================================
        'o-globe-alt' => 'fas-globe',
        'o-globe-americas' => 'fas-earth-americas',
        'o-globe-europe-africa' => 'fas-earth-europe',
        'o-map' => 'fas-map',
        'o-map-pin' => 'fas-location-dot',

        // =====================================================================
        // Misc UI Elements
        // =====================================================================
        'o-archive-box' => 'fas-box-archive',
        'o-battery-100' => 'fas-battery-full',
        'o-battery-50' => 'fas-battery-half',
        'o-battery-0' => 'fas-battery-empty',
        'o-cursor-arrow-rays' => 'fas-arrow-pointer',
        'o-ellipsis-horizontal' => 'fas-ellipsis',
        'o-ellipsis-vertical' => 'fas-ellipsis-vertical',
        'o-rectangle-stack' => 'fas-layer-group',
        'o-squares-plus' => 'fas-grip',
        'o-squares-2x2' => 'fas-grip',
        'o-inbox' => 'fas-inbox',
        'o-folder' => 'fas-folder',
        'o-folder-open' => 'fas-folder-open',
        'o-home' => 'fas-house',
        'o-building-office' => 'fas-building',
        'o-no-symbol' => 'fas-ban',
        'o-paper-airplane' => 'fas-paper-plane',
        'o-share' => 'fas-share',
        'o-arrow-right-on-rectangle' => 'fas-right-from-bracket',
        'o-arrow-left-on-rectangle' => 'fas-right-to-bracket',
        'o-bars-3' => 'fas-bars',
        'o-book-open' => 'fas-book-open',
        'o-flag' => 'fas-flag',

        // =====================================================================
        // Solid Heroicons (s- prefix)
        // =====================================================================
        's-check' => 'fas-check',
        's-check-circle' => 'fas-circle-check',
        's-x-mark' => 'fas-xmark',
        's-x-circle' => 'fas-circle-xmark',
        's-heart' => 'fas-heart',
        's-star' => 'fas-star',
        's-user' => 'fas-user',
    ],

    /*
    |--------------------------------------------------------------------------
    | Financial Icon Enhancements
    |--------------------------------------------------------------------------
    |
    | Suggested FontAwesome icons for financial use cases that don't have
    | direct Heroicon equivalents. Use these for improved UX.
    |
    */
    'financial_icons' => [
        'piggy_bank' => 'fas-piggy-bank',          // Savings pots
        'wallet' => 'fas-wallet',                   // Budgets
        'money_transfer' => 'fas-money-bill-transfer', // Transfers
        'income' => 'fas-hand-holding-dollar',     // Receiving money
        'receipt' => 'fas-receipt',                // Transaction receipts
        'invoice' => 'fas-file-invoice-dollar',   // Bills/invoices
        'coins' => 'fas-coins',                    // Small amounts
        'vault' => 'fas-vault',                    // Secure savings
        'calculator' => 'fas-calculator',          // Calculations
        'sack_dollar' => 'fas-sack-dollar',       // Large sums
        'cash_register' => 'fas-cash-register',   // Point of sale
        'percent' => 'fas-percent',                // Interest rates
        'chart_pie' => 'fas-chart-pie',           // Budget breakdown
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Card Brand Icons
    |--------------------------------------------------------------------------
    |
    | Brand icons for payment card types. Use these based on card scheme.
    |
    */
    'card_brand_icons' => [
        'mastercard' => 'fab-cc-mastercard',
        'visa' => 'fab-cc-visa',
        'amex' => 'fab-cc-amex',
        'american express' => 'fab-cc-amex',
        'discover' => 'fab-cc-discover',
        'diners' => 'fab-cc-diners-club',
        'jcb' => 'fab-cc-jcb',
        'apple pay' => 'fab-cc-apple-pay',
        'google pay' => 'fab-google-pay',
        'paypal' => 'fab-cc-paypal',
        'stripe' => 'fab-cc-stripe',
        'amazon' => 'fab-cc-amazon-pay',
        'default' => 'fas-credit-card',
    ],

    /*
    |--------------------------------------------------------------------------
    | Integration Brand Icons
    |--------------------------------------------------------------------------
    |
    | Brand icons for third-party integrations.
    |
    */
    'integration_brand_icons' => [
        'github' => 'fab-github',
        'spotify' => 'fab-spotify',
        'slack' => 'fab-slack',
        'reddit' => 'fab-reddit',
        'google' => 'fab-google',
        'apple' => 'fab-apple',
        'discord' => 'fab-discord',
        'twitter' => 'fab-x-twitter',
        'bluesky' => 'fas-cloud',  // No official Bluesky icon yet
        'trello' => 'fab-trello',
    ],
];
