<?php

return [
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
