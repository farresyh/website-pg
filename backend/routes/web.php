<?php

use App\Http\Controllers\Middleware\OpsAccessController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ADR-048 addendum: the one route in this API-only backend that runs
// through the `web` session guard — see OpsAccessController's own doc
// comment. `signed` middleware rejects a tampered/expired link before the
// controller ever runs; the controller re-checks is_active/role itself.
Route::get('/ops/enter', [OpsAccessController::class, 'enter'])
    ->middleware(['web', 'signed'])
    ->name('ops.enter');
