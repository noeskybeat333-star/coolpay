<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Оперативный API Wildberries отдаёт заказы только за последние 90 дней,
// и окно скользит: не забранный вовремя заказ исчезает из выдачи навсегда.
// Берём всё окно целиком, а не 30 дней: заказ живёт дольше месяца —
// отправка, доставка, возврат, — и с коротким окном его статус
// замирал бы на том, каким был при последнем попадании в выдачу.
Schedule::command('marketplace:import-orders --days=90')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
