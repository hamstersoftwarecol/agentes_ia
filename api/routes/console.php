<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Avisos de fin de prueba. 13:00 UTC = primera hora de la mañana en Latinoamérica. Requiere `schedule:run` cada minuto
// (cron) o `schedule:work` en desarrollo, y un worker de colas para que los correos salgan.
Schedule::command('billing:lifecycle')->dailyAt('13:00')->withoutOverlapping();
