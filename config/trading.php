<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Commission Recipient User ID
    |--------------------------------------------------------------------------
    |
    | User ID that should receive collected commission. Defaults to 1.
    |
    */
    'commission_user_id' => env('TRADING_COMMISSION_USER_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Trading Commission Rate
    |--------------------------------------------------------------------------
    |
    | Commission applied to the seller on successful matches (as a decimal).
    | Example: 0.015 = 1.5%
    |
    */
    'commission_rate' => env('TRADING_COMMISSION_RATE', 0.015),
];
