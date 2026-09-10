---
title: Idempotency & retries
description: How idempotency_key prevents duplicate orders, and how to retry safely.
---

`POST /v1/orders` requires an `idempotency_key`. It is the mechanism that stops
a network retry from charging your wallet twice.

## The rule

**Generate one fresh UUID per logical order.** Reuse the *same* key for every
retry of *that* order; never reuse a key for a different order.

```json
{
  "product_code": "MLMY-86",
  "player_id": "123456789",
  "idempotency_key": "8f2b8c4e-1d3a-4a9c-9b1e-2f6a7c0d5e11"
}
```

The key must be 8–100 characters. A UUID v4 is the recommended form.

## What each outcome looks like

| You send | You get back |
| --- | --- |
| A **new** key | **HTTP 201** + the newly-created order. Wallet charged once. |
| The **same** key **and the same** `product_code` / `player_id` / `server_id` | **HTTP 200** + the **original** order, plus a header `Idempotent-Replayed: true`. Wallet **not** charged again. |
| The **same** key but a **different** payload | **HTTP 409 `IDEMPOTENCY_KEY_CONFLICT`**. Nothing happens. Use a fresh key. |

The 200-with-`Idempotent-Replayed` response is how a safe retry looks: you get
the real order, and you can tell it was a replay.

## How to retry

If a `POST /v1/orders` call times out or returns a `5xx`, **retry it with the
exact same body, including the same `idempotency_key`.** One of three things is
true:

- The first attempt never landed → the retry creates the order (201).
- The first attempt landed → the retry returns that order
  (200, `Idempotent-Replayed: true`).
- You accidentally changed the payload → 409, and you know to investigate.

Back off between retries (for example 1s, 2s, 4s) and give up after a few
attempts, then reconcile with `GET /v1/orders`.

## Retention

Idempotency keys are kept for the life of the order (indefinitely). There is no
window after which a key "expires" and a replay becomes a new charge.
