<?php

return [
    'currency' => [
        'code' => env('APP_CURRENCY', 'SOS'),
        'label' => env('APP_CURRENCY_LABEL', 'Slsh'),
        'decimals' => (int) env('APP_CURRENCY_DECIMALS', 0),
    ],
];
