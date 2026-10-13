<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Canonical spending categories
    |--------------------------------------------------------------------------
    |
    | The vocabulary shared by budgets, the Spending list, Flint and Jev. Monzo's
    | own categories plus the user's named custom categories sit on top of this.
    |
    */

    'categories' => [
        'groceries', 'eating_out', 'transport', 'shopping', 'entertainment', 'bills', 'holidays',
        'personal_care', 'family', 'general', 'expenses', 'charity', 'cash', 'gifts', 'finances', 'uncategorised',
    ],

    /*
    |--------------------------------------------------------------------------
    | Spending classification
    |--------------------------------------------------------------------------
    |
    | Rules decide what counts as spending; Jev only answers narrow typed
    | questions about the ambiguous remainder. In shadow mode answers are
    | recorded in event metadata (event_metadata.money_assessment) and never
    | acted on. Thresholds are per question and tuned for the pinned Jev model.
    |
    */

    'jev' => [
        'enabled' => env('MONEY_JEV_ENABLED', false),
        'shadow' => env('MONEY_JEV_SHADOW', true),
        'budget_seconds' => 5.0,
        'batch_size' => 25,
        'thresholds' => [
            'spending_class' => 0.6,
            'category' => 0.5,
            'recurring' => 0.6,
        ],
    ],

    /*
    | Phrases that mark an open-banking line as a card repayment or pending noise
    | rather than spending.
    */

    'repayment_phrases' => [
        'DIRECT DEBIT PAYMENT - THANK YOU',
        'PAYMENT RECEIVED - THANK YOU',
        'OUTSTANDING AUTHORISATION',
    ],
    /*
    | Days an open-banking authorisation that never settles keeps counting as spending.
    */

    'pending_expiry_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Add to buffer
    |--------------------------------------------------------------------------
    |
    | Moving money from a Monzo account into the card buffer pot. Off until Monzo
    | has been re-authorised for writes. Always needs an explicit confirmation,
    | never runs on a schedule, and is capped per action.
    |
    */

    'buffer' => [
        'enabled' => env('MONEY_BUFFER_ENABLED', false),
        'cap_pence' => (int) env('MONEY_BUFFER_CAP_PENCE', 500_000),
        'link_window_hours' => 48,
        'lock_wait_seconds' => 5,
    ],
];
