<?php

use App\Http\Controllers\ApiRootController;
use App\Http\Controllers\Middleware\OpsAccessController;
use Illuminate\Support\Facades\Route;

// ADR-078 decision 4: an API-only backend — a flat JSON identity, no
// Blade / no `route()` / no closure (was Laravel's stock welcome view,
// which threw RouteNotFoundException on bot traffic and left
// `route:cache` in an ambiguous state).
Route::get('/', ApiRootController::class);

// ADR-048 addendum: the one route in this API-only backend that runs
// through the `web` session guard — see OpsAccessController's own doc
// comment. `signed` middleware rejects a tampered/expired link before the
// controller ever runs; the controller re-checks is_active/role itself.
Route::get('/ops/enter', [OpsAccessController::class, 'enter'])
    ->middleware(['web', 'signed'])
    ->name('ops.enter');
