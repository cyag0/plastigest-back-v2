<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Schedule recurring tasks generation daily at 6 AM
Schedule::command('tasks:generate-recurring')->dailyAt('06:00');

// Avisos que dependen del reloj: tareas por vencer o vencidas y recordatorios.
// Cada hora en horario de trabajo (hora de México, APP_TIMEZONE) para no mandar
// push de madrugada; cada aviso sale una sola vez.
Schedule::command('notifications:scheduled')->hourly()->between('7:00', '21:00')->withoutOverlapping();

// Mantenimiento. Requiere el cron del scheduler (`php artisan schedule:run`
// cada minuto): Scheduled Task en Coolify o crontab en el VPS (ver DEPLOY*.md).
// Jobs fallidos de más de 7 días (la tabla failed_jobs crece sin límite).
Schedule::command('queue:prune-failed --hours=168')->dailyAt('03:30');
// Tokens de Sanctum vencidos hace más de 24 h (con SANCTUM_TOKEN_EXPIRATION).
Schedule::command('sanctum:prune-expired --hours=24')->dailyAt('03:45');
