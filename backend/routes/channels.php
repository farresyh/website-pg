<?php

use Illuminate\Support\Facades\Broadcast;

// ADR-047 decision 2: storefront order-status updates broadcast on a
// PUBLIC channel (`order.{order_number}`) — no entry needed here.
// `order_number` is a Str::ulid() (OrderNumberService), already treated as
// an unguessable bearer-token-equivalent by the existing unauthenticated
// `GET /api/track-order/{orderNumber}` endpoint; this channel relies on the
// identical trust boundary, deliberately not gated by a channel-auth check.

// ADR-047 decision 3: admin-side channels (Price Sync / Backups / Sandbox /
// Dashboard health run-status) are PRIVATE, authorized here against the
// authenticated admin user — same bearer-token boundary every other
// `auth:sanctum` route already requires. Added as each of those four
// conversions ships (see docs/prd.md's pointer list, items 2-5 of
// ADR-047 decision 1's sequence) — none built yet.
