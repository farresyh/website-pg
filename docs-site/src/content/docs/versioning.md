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
- new `error` codes.

Write your integration to tolerate these — ignore response fields you do not
recognise, and treat an unknown `error` code by its HTTP status.

**A breaking change means a new version, `/v2`.** When that happens:

- `/v1` keeps working for **at least 6 months** after `/v2` is generally
  available,
- `/v1` responses carry a `Sunset` header with the retirement date,
- this page gets a deprecation notice with the migration guide.

The version number on this documentation (shown in the API Reference) is the
**docs revision**, not the API version.

## Changelog

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
