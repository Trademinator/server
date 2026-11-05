<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trusted exchange taker-fee ceilings for training/economic evaluation
    |--------------------------------------------------------------------------
    |
    | Trademinator training and candle-period selection use the highest known
    | applicable one-side taker fee. If CCXT reports a lower market/account fee,
    | the configured published ceiling wins so training remains conservative.
    | Keep every ceiling sourced and review it when an exchange changes fees.
    |
    | Rates are decimal fractions: 0.002 = 0.20%, 0.005 = 0.50%.
    |
    */
    'taker_overrides' => [
        'ndax' => [
            'rate' => 0.002,
            'reviewed_at' => '2026-10-01',
            'source' => 'https://ndax.io/en/fees',
            'note' => 'NDAX publishes a flat 0.20% fee per buy or sell.',
        ],
        'cryptocom' => [
            'rate' => 0.005,
            'reviewed_at' => '2026-10-01',
            'source' => 'https://crypto.com/exchange/document/fees-limits',
            'note' => 'Crypto.com Exchange Level 1 base spot taker fee without CRO balance is 0.50%; account volume/CRO discounts may reduce the actual fee.',
        ],
        'poloniex' => [
            'rate' => 0.002,
            'reviewed_at' => '2026-11-05',
            'source' => 'https://www.poloniex.com/fee',
            'note' => 'Poloniex VIP 0 spot maker/taker fee is 0.20%; VIP tiers and TRX/HTX deductions may reduce the actual fee.',
        ],
        'kraken' => [
            'rate' => 0.008,
            'reviewed_at' => '2026-10-02',
            'source' => 'https://www.kraken.com/features/fee-schedule',
            'note' => 'Kraken Pro Tier 1 spot taker fee is 0.80%; higher-volume/AoP tiers reduce the fee, so training uses the 0.80% ceiling.',
        ],
    ],
];
