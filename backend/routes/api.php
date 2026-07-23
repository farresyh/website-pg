<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Webhooks\XenditWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
});

// Not behind auth:sanctum — Xendit isn't an admin user. Signature
// verification inside the controller is the auth mechanism (PAY-1).
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
