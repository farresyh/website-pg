<?php

use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\VoucherController;
use App\Http\Controllers\Admin\WithdrawalController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\Middleware\PaymentMethodController;
use App\Http\Controllers\Middleware\PlayerRegionMappingController;
use App\Http\Controllers\Middleware\PlayerValidatorProfileController;
use App\Http\Controllers\Middleware\SupplierProductController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\Webhooks\XenditWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

// Guest checkout (ADR-011) — no Customer auth exists, deliberately not
// behind auth:sanctum. Money fields are still never client-trusted
// (ORD-9): CheckoutController resolves everything from stored
// Game/Package config, never from this request's own body.
Route::post('/checkout', [CheckoutController::class, 'store']);

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

    // ORD-1..7 — list + detail, plus retryDelivery (ORD-7's resolve
    // action, ADR-014). Makes a real order's outcome visible in the
    // Admin Panel and gives an operator a way to act on a failure.
    Route::middleware('admin.role:super_admin,admin')->prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::get('/{order}', [OrderController::class, 'show']);
        Route::post('/{order}/retry-delivery', [OrderController::class, 'retryDelivery']);
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

    // SET-7/SET-11 — Payment Methods: per-channel activation/fee/gateway
    // management, replaces the config/checkout.php stopgap.
    Route::middleware('admin.role:super_admin,admin')->prefix('middleware/payment-methods')->group(function () {
        Route::get('/', [PaymentMethodController::class, 'index']);
        Route::patch('/{payment_method}/status', [PaymentMethodController::class, 'updateStatus']);
        Route::patch('/{payment_method}/fee', [PaymentMethodController::class, 'updateFee']);
        Route::post('/{payment_method}/test', [PaymentMethodController::class, 'test']);
    });

    // MUI-5 — Validators: admin-created profiles, each bound to a real
    // backend implementation via `key` (PlayerValidatorRegistry).
    // Region-routing mappings are nested under the profile they
    // belong to, not a flat list — see PlayerValidatorProfileController's
    // doc comment for why (founder correction, 2026-07-25).
    Route::middleware('admin.role:super_admin,admin')->prefix('middleware/validators')->group(function () {
        Route::get('/', [PlayerValidatorProfileController::class, 'index']);
        Route::get('/available-keys', [PlayerValidatorProfileController::class, 'availableKeys']);
        Route::post('/', [PlayerValidatorProfileController::class, 'store']);
        Route::put('/{validator}', [PlayerValidatorProfileController::class, 'update']);
        Route::delete('/{validator}', [PlayerValidatorProfileController::class, 'destroy']);
        Route::post('/{validator}/test', [PlayerValidatorProfileController::class, 'test']);

        Route::post('/{validator}/mappings', [PlayerRegionMappingController::class, 'store']);
        Route::put('/{validator}/mappings/{mapping}', [PlayerRegionMappingController::class, 'update']);
        Route::delete('/{validator}/mappings/{mapping}', [PlayerRegionMappingController::class, 'destroy']);
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
