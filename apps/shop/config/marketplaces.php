<?php

use App\Integrations\Drivers\WildberriesDriver;
use App\Integrations\Drivers\UnsupportedDriver;
use App\Integrations\Drivers\YandexMarketDriver;

return [
    'drivers' => [
        'wildberries' => WildberriesDriver::class,
        'ozon' => UnsupportedDriver::class,
        'yandex-market' => YandexMarketDriver::class,
        'megamarket' => UnsupportedDriver::class,
        'mvideo' => UnsupportedDriver::class,
        'magnit-market' => UnsupportedDriver::class,
        'custom' => UnsupportedDriver::class,
    ],
];
