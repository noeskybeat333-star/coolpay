<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Оперативный API Wildberries отдаёт заказы только за последние 90 дней,
// и окно скользит: не забранный вовремя заказ исчезает из выдачи навсегда.
// Ежечасный импорт с запасом в 30 дней сам закрывает пропуски, если
// несколько запусков были потеряны.
Schedule::command('marketplace:import-orders --days=30')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
