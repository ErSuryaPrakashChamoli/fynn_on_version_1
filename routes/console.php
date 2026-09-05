<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * OCR queue worker health checks (Supervisor runs the workers themselves;
 * these just surface it in the log when something's wrong).
 */
Schedule::command('queue:monitor database:default --max=20')
    ->everyFiveMinutes();

Schedule::command('ocr:check-stuck')
    ->everyThirtyMinutes();

Schedule::command('journey:check-sla-breaches')
    ->everyFiveMinutes();

/*
 * Daily Commitment: freeze yesterday's commitments (MET / OVERACHIEVED /
 * FAILED) once the day is over. The module's screens always compute live,
 * so this only keeps the stored history honest.
 */
Schedule::command('daily-commitment:settle')
    ->dailyAt('00:30');
