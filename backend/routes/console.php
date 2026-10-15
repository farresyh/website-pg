<?php

use App\Console\TaskSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-122: every scheduled task is defined in TaskSchedule, so the
// liveness check (an HTTP request, which never loads this file) reads the
// same definitions the scheduler runs.
TaskSchedule::define(app(Schedule::class));
