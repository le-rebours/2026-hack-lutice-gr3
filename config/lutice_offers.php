<?php
// config/lutice_offers.php

return [
    'start2' => [
        'label' => 'Start 2',
        'instance' => 'Start-2-S-SSD',
        'price_month' => null,              // null => “À définir”
        'users_limit' => null,              // null => “À définir”
        'storage_gb' => 50,                 // CDC: Start-50
        'features' => [
            'Greenlight v3 dédié',
            'HTTPS inclus',
            'Support email (MVP)',
        ],
    ],
    'start3' => [
        'label' => 'Start 3',
        'instance' => 'Start-3-S-SSD',
        'price_month' => null,
        'users_limit' => null,
        'storage_gb' => 200,                // CDC: Start-200
        'features' => [
            'Greenlight v3 dédié',
            'HTTPS inclus',
            'Support prioritaire (MVP)',
        ],
    ],
];
