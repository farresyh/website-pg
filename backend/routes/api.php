<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\Middleware\SupplierProductController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\Webhooks\XenditWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // AUTH-4 — Super Admin only, per PRD §3 Users & Roles.
    Route::middleware('admin.role:super_admin')->prefix('admin-users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index']);
        Route::post('/', [AdminUserController::class, 'store']);
        Route::put('/{admin_user}', [AdminUserController::class, 'update']);
        Route::patch('/{admin_user}/status', [AdminUserController::class, 'updateStatus']);
    });

    // WTH-1..5 — Admin and Super Admin both operate this; the
    // maker-checker threshold check (WTH-5) is enforced inside
    // WithdrawalController::approve(), not at the route level, since
    // it depends on each withdrawal's own amount.
    Route::middleware('admin.role:super_admin,admin')->prefix('withdrawals')->group(function () {
        Route::get('/', [WithdrawalController::class, 'index']);
        Route::post('/', [WithdrawalController::class, 'store']);
        Route::patch('/{withdrawal}/approve', [WithdrawalController::class, 'approve']);
        Route::patch('/{withdrawal}/reject', [WithdrawalController::class, 'reject']);
        Route::patch('/{withdrawal}/complete', [WithdrawalController::class, 'complete']);
    });

    // VCH-1..6 — Path A (store) is threshold-gated inside the
    // controller (VCH-6); Path B (storeFromOrder) never is, per the
    // founder's decision (docs/prd.md §14) — its amount is bounded by
    // what the customer actually paid, not an open admin choice.
    Route::middleware('admin.role:super_admin,admin')->group(function () {
        Route::prefix('vouchers')->group(function () {
            Route::get('/', [VoucherController::class, 'index']);
            Route::post('/', [VoucherController::class, 'store']);
            Route::patch('/{voucher}/revoke', [VoucherController::class, 'revoke']);
        });
        Route::post('/orders/{order}/voucher', [VoucherController::class, 'storeFromOrder']);
    });

    // MID-1..6/SUPP-3 — Price Sync Stage 2: browse the raw Gamevion
    // mirror (supplier_products), link a category group to a Game
    // once, then promote individual rows into real Packages.
    Route::middleware('admin.role:super_admin,admin')->prefix('middleware/supplier-products')->group(function () {
        Route::get('/categories', [SupplierProductController::class, 'categories']);
        Route::post('/categories/link', [SupplierProductController::class, 'linkCategory']);
        Route::get('/', [SupplierProductController::class, 'index']);
        Route::post('/{supplier_product}/promote', [SupplierProductController::class, 'promote']);
    });

    // GAME-1..5/7 — Admin Games & Packages management.
    Route::middleware('admin.role:super_admin,admin')->group(function () {
        Route::get('/games', [GameController::class, 'index']);
        Route::get('/games/{game}', [GameController::class, 'show']);
        Route::put('/games/{game}', [GameController::class, 'update']);
        Route::delete('/games/{game}', [GameController::class, 'destroy']);
        Route::get('/games/{game}/packages', [GameController::class, 'packages']);

        Route::put('/packages/{package}', [PackageController::class, 'update']);
        Route::patch('/packages/{package}/markup', [PackageController::class, 'updateMarkup']);
        Route::patch('/packages/{package}/status', [PackageController::class, 'updateStatus']);
        Route::delete('/packages/{package}', [PackageController::class, 'destroy']);
    });
});

// Not behind auth:sanctum — Xendit isn't an admin user. Signature
// verification inside the controller is the auth mechanism (PAY-1).
Route::post('/webhooks/xendit', [XenditWebhookController::class, 'handle']);
