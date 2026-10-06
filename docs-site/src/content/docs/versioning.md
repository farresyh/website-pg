---
title: Versioning & changelog
description: The API's versioning policy and a dated log of changes.
---

## Policy

The API version is the **`/v1`** in the path.

**Additive, backward-compatible changes ship without a version bump.** That
includes:

- new endpoints,
- new optional request fields,
- new fields in a response object,
- new `error` codes,
- new `delivery_status` values and new webhook event types.

Write your integration to tolerate these — ignore response fields you do not
recognise, treat an unknown `error` code by its HTTP status, and treat an
unknown `delivery_status` or webhook event as "fetch the order and check".

**A breaking change means a new version, `/v2`.** When that happens:

- `/v1` keeps working for **at least 6 months** after `/v2` is generally
  available,
- `/v1` responses carry a `Sunset` header with the retirement date,
- this page gets a deprecation notice with the migration guide.

The version number on this documentation (shown in the API Reference) is the
**docs revision**, not the API version.

## Changelog

### 2026-10 — v1.3.0

- `GET /v1/catalog`: `checkout_input` gains `player_id_format` (`numeric` or
  `text`).
- `POST /v1/orders` now checks each game's full input contract (see
  [Your first order](/first-order/)). **Input that used to be accepted can now
  be rejected** with `422 VALIDATION_FAILED`: a `server_id` on a game whose
  `checkout_input.field` is `null`, a non-digit `player_id` on a `numeric`
  game, a non-digit Server ID, a value with spaces, or one over 64
  characters. Before, such an order was charged and then failed at the
  supplier. The response shape and error code are unchanged.

### 2026-10 — v1.2.0

- New final `delivery_status`: `partially_delivered` — part of an order was
  delivered and the rest failed. The undelivered share is refunded to the
  wallet as a separate action; `wallet_refund.amount_sen` shows how much.
- New webhook event `order.partially_delivered`. `order.refunded` now also
  covers a partially delivered order, for the undelivered share only.
- The additive-change list above now names new status values and new webhook
  events.

### 2026-09 — v1.1.0

- Order responses (create/replay, get, and list) and delivery webhooks now
  include `wallet_refunded` and `wallet_refund` (amount in sen and refund time).
  These are additive fields; the API path remains `/v1`.
- Clarified that `delivery_status: failed` does not itself mean a wallet refund
  has occurred. `order.refunded` announces the separate wallet credit.

### 2026-09 — v1.0.0

Initial public documentation of the `/v1` API.

- `GET /v1/balance`, `GET /v1/catalog`, `POST /v1/orders`,
  `GET /v1/orders/{order_number}`.
- `GET /v1/orders` — cursor-paginated order history with `?status=` and
  `?created_after=` filters.
- Delivery webhook — `order.delivered` / `order.failed` / `order.refunded`,
  signed `X-Hub-Signature-256`.
- Stable `error` codes on every 4xx; `422` carries `details`.
- Idempotency: a replay returns the original order with HTTP 200 and
  `Idempotent-Replayed: true`; a key reused with a different payload is a
  `409`.
- `price_sen` is an integer (sen) everywhere.
- Optional per-key IP allowlist.
