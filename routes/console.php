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
 * Follow-up reminders: every follow-up coming due lands in its owner's
 * bell and reminder pop-up a few minutes before its time.
 */
Schedule::command('follow-ups:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

/*
 * Daily Commitment: freeze yesterday's commitments (MET / OVERACHIEVED /
 * FAILED) once the day is over. The module's screens always compute live,
 * so this only keeps the stored history honest.
 */
Schedule::command('daily-commitment:settle')
    ->dailyAt('00:30');

/*
 * The client demo is seeded relative to today, so it is rebuilt every
 * night to keep targets, commitments and follow-ups current. Only the
 * demo environment ever runs it (the command refuses anywhere else too).
 */
Schedule::command('demo-environment:refresh --force')
    ->dailyAt('00:10')
    ->when(fn (): bool => app()->environment('demo') && (bool) config('demo.enabled'));

/*
 * Academy / Demo portals: deactivate demo and training accounts whose
 * expiry has passed. An expired account is already refused at request
 * time by PortalAccount::isUsable(), so this only keeps the stored
 * state honest for the listings and reports.
 */
Schedule::command('portal:expire-accounts')
    ->hourly();

/*
 * Close login sessions nobody came back to. EnforceIdleTimeout only
 * fires when the user makes another request, so a closed laptop would
 * otherwise leave its row open indefinitely — showing as "Active" in the
 * login log and inflating session duration.
 */
Schedule::command('sessions:close-idle')
    ->everyFiveMinutes();

/*
 * /demo counterparts of the jobs above.
 *
 * /demo runs the same application against the demo database, so its data
 * needs the same upkeep (SLA escalations, commitment settlement, idle
 * sessions, stuck OCR documents). Each runs through `demo:run`, which
 * executes the unchanged command inside the demo context — the demo
 * database only. Switched off with DEMO_PANEL_ENABLED=false or
 * DEMO_SCHEDULE_ENABLED=false.
 */
if (config('demo.panel_enabled') && config('demo.schedule_enabled')) {
    Schedule::command('demo:run queue:monitor demo:default --max=20')
        ->everyFiveMinutes();

    Schedule::command('demo:run ocr:check-stuck')
        ->everyThirtyMinutes();

    Schedule::command('demo:run journey:check-sla-breaches')
        ->everyFiveMinutes();

    Schedule::command('demo:run daily-commitment:settle')
        ->dailyAt('00:35');

    Schedule::command('demo:run sessions:close-idle')
        ->everyFiveMinutes();

    Schedule::command('demo:run follow-ups:send-reminders')
        ->everyFiveMinutes()
        ->withoutOverlapping();
}
