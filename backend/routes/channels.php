<?php

use App\Models\AdminUser;
use Illuminate\Support\Facades\Broadcast;

// ADR-047 decision 2: storefront order-status updates broadcast on a
// PUBLIC channel (`order.{order_number}`) — no entry needed here.
// `order_number` is a Str::ulid() (OrderNumberService), already treated as
// an unguessable bearer-token-equivalent by the existing unauthenticated
// `GET /api/track-order/{orderNumber}` endpoint; this channel relies on the
// identical trust boundary, deliberately not gated by a channel-auth check.

// ADR-047 decision 3: admin-side channels are PRIVATE, authorized against
// the authenticated admin user resolved by `auth:sanctum` on
// `POST /api/broadcasting/auth` (bootstrap/app.php) — each channel's role
// check mirrors its own REST routes' `admin.role:` middleware exactly
// (routes/api.php), so a channel never grants broadcast access a role
// couldn't already get through the ordinary API.

// Price Sync (`/middleware/price-sync`) — super_admin only, one channel
// per run (mirrors PriceSyncController::show()'s per-run lookup).
Broadcast::channel('price-sync-run.{runId}', function (AdminUser $admin, int $runId) {
    return $admin->role === 'super_admin' && $admin->is_active;
});

// Backups (`/middleware/backups`) — super_admin only, one admin-wide
// channel (the screen refetches its whole list/stats on any change, not
// one run's fields incrementally — see BackupRunUpdated's own doc comment).
Broadcast::channel('backups', function (AdminUser $admin) {
    return $admin->role === 'super_admin' && $admin->is_active;
});

// Orders (`/admin/orders`) — ADR-047 addendum (2026-09-19). Mirrors
// routes/api.php's `admin.role:super_admin,admin` group on `/api/orders`
// exactly (both roles, not super_admin-only like Price Sync/Backups) —
// this channel never grants broadcast access a role couldn't already get
// through the ordinary REST endpoints. One admin-wide channel, same
// "refetch on any change" shape as `backups`.
Broadcast::channel('admin-orders', function (AdminUser $admin) {
    return in_array($admin->role, ['super_admin', 'admin'], true) && $admin->is_active;
});
