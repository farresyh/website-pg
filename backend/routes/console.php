<?php

use App\Jobs\SyncSupplierPricesJob;
use App\Models\PriceSyncRun;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ADR-015 decision #6: written now so it activates for free the
// moment a real OS cron (`* * * * * php artisan schedule:run`) exists
// on a deployed host — this project currently runs on local Herd
// only, so this entry is inert today, not a claim that scheduled
// Price Sync is actually running in production yet. Writes its own
// PriceSyncRun row (triggered_by='system') the same way the manual
// "Sync All Prices Now" trigger does, so Sync History (ADR-016) shows
// both uniformly once that UI exists.
$priceSyncIntervalMinutes = config('packages.price_sync_interval_minutes');

Schedule::call(function () {
    $run = PriceSyncRun::query()->create(['status' => 'queued', 'triggered_by' => 'system']);
    SyncSupplierPricesJob::dispatch($run);
})->cron("*/{$priceSyncIntervalMinutes} * * * *")
    ->name('price-sync')
    ->withoutOverlapping();
