---
title: Delivery notifications
description: Register a webhook to receive signed order.delivered / order.failed / order.refunded events.
---

Instead of polling every order, you can register **one webhook endpoint** and
have PekanGame POST you a signed event when an order reaches a terminal state.
Polling `GET /v1/orders/{order_number}` always remains available as a fallback.

## Setup

In the partner portal, under **API Keys → Delivery webhook**:

1. Enter your endpoint **URL** (must be HTTPS).
2. Copy the **signing secret** — it is shown **once**. Store it as a server
   secret.
3. You can **pause / resume** the endpoint, **rotate** the secret, or **remove**
   it at any time. A recent-deliveries table shows every attempt and its
   result.

PekanGame support can also set this for you.

## Events

| Event | When |
| --- | --- |
| `order.delivered` | The order was fulfilled successfully. |
| `order.failed` | Delivery failed. The wallet refund follows (see `order.refunded`). |
| `order.refunded` | A failed order was refunded to your wallet. |

Each event fires **at most once per order**.

## Payload

`POST` with a JSON body — the order's public shape plus three envelope fields:

```json
{
  "order_number": "PG-7QK2M9X4RJ",
  "product_code": "MLMY-86",
  "player_id": "123456789",
  "server_id": "2201",
  "price_sen": 6300,
  "payment_status": "paid",
  "delivery_status": "delivered",
  "created_at": "2026-09-10T09:14:52+00:00",
  "delivered_at": "2026-09-10T09:15:07+00:00",
  "event": "order.delivered",
  "event_id": "b3d1c2a4-5e6f-4708-9a1b-2c3d4e5f6071",
  "occurred_at": "2026-09-10T09:15:08+00:00"
}
```

`event_id` is unique per event — use it for your own idempotency if the same
event is delivered more than once.

## Headers

| Header | Value |
| --- | --- |
| `X-Hub-Signature-256` | `sha256=` + hex HMAC-SHA256 of the **raw request body** keyed with your signing secret |
| `X-Webhook-Event` | the event name (e.g. `order.delivered`) |
| `X-Webhook-Id` | the same value as `event_id` |

## Verifying the signature

Compute HMAC-SHA256 over the exact raw bytes of the request body, using your
signing secret as the key, and compare it (constant-time) to the hex digest in
`X-Hub-Signature-256` after the `sha256=` prefix. Reject the request if it does
not match.

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

function verify(rawBody, header, secret) {
  const expected = 'sha256=' + createHmac('sha256', secret).update(rawBody).digest('hex');
  const a = Buffer.from(header ?? '');
  const b = Buffer.from(expected);
  return a.length === b.length && timingSafeEqual(a, b);
}
```

```php
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);
if (! hash_equals($expected, $request->header('X-Hub-Signature-256', ''))) {
    abort(401);
}
```

## Delivery & retries

- Respond with any **2xx** to acknowledge. Anything else (or a timeout) is a
  failure.
- A failed delivery is retried with exponential backoff — roughly **5 attempts
  over about an hour** — then marked `exhausted` in the portal's
  deliveries table.
- Retries are per-event; a later event for the same order is independent.
- If your endpoint is down for longer than that, **reconcile with
  `GET /v1/orders`** (filter by `?status=` and `?created_after=`).

## If your signing secret leaks

Rotate it in the portal. The old secret stops working immediately; deploy the
new one to your receiver.
