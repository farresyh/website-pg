---
title: Your first order
description: An end-to-end walkthrough — read the catalogue, place an order, and get the result.
---

This walkthrough places one order from start to finish. Paths are shown
relative to the [base URL](/introduction/#base-url) — prepend
`https://api.pekangame.space/api/reseller`. Every request needs the
`Authorization: Bearer` header from [Authentication](/authentication/); it is
omitted below for brevity.

## 1. Check your balance

```http
GET /v1/balance
```

```json
{ "balance_sen": 125000 }
```

All money is in **sen** (RM 1.00 = `100`). `125000` is RM 1,250.00.

## 2. Read the catalogue

```http
GET /v1/catalog
```

```json
{
  "games": [
    {
      "code": "MLMY",
      "name": "Mobile Legends (Malaysia)",
      "checkout_input": { "field": "zone_id", "options": ["SouthEastAsia", "MENA"] },
      "packages": [
        { "code": "MLMY-14", "name": "14 Diamonds", "price_sen": 1200 },
        { "code": "MLMY-86", "name": "86 Diamonds", "price_sen": 6300 }
      ]
    }
  ]
}
```

`price_sen` is **your** price for that package, in sen. Order against
`packages[].code` — see [Product codes](/product-codes/).

`checkout_input` tells you, per game, whether placing an order needs an
extra value beyond `player_id` — `field`/`options` are both `null` when it
doesn't. When present, send that value as `server_id` on the order below
(the request field is always named `server_id`, regardless of what
`checkout_input.field` calls it for that particular game) — `options`, when
present, is the exact picklist of valid values; anything else is rejected.

## 3. Place the order

```http
POST /v1/orders
Content-Type: application/json

{
  "product_code": "MLMY-86",
  "player_id": "123456789",
  "server_id": "2201",
  "idempotency_key": "8f2b8c4e-1d3a-4a9c-9b1e-2f6a7c0d5e11"
}
```

- `server_id` is required only for games whose catalog entry has a non-null
  `checkout_input` (Mobile Legends does; many games don't) — check that
  field programmatically rather than hardcoding which games need it.
- `idempotency_key` is a **fresh UUID you generate per order**. See
  [Idempotency & retries](/idempotency/).

On success you get **HTTP 201** and the order:

```json
{
  "order_number": "PG-7QK2M9X4RJ",
  "product_code": "MLMY-86",
  "player_id": "123456789",
  "server_id": "2201",
  "price_sen": 6300,
  "payment_status": "paid",
  "delivery_status": "not_started",
  "wallet_refunded": false,
  "wallet_refund": null,
  "created_at": "2026-09-10T09:14:52+00:00",
  "delivered_at": null
}
```

Your wallet is charged `price_sen` at this moment. `payment_status` is `paid`
straight away (it is a wallet debit). `delivery_status` then progresses on its
own — see the table below.

### Order status values

`payment_status` is always `paid` for a wallet order.

| `delivery_status` | Meaning |
| --- | --- |
| `not_started` | Accepted, not yet submitted for fulfilment. |
| `processing` | Being fulfilled. |
| `pending` | Submitted; awaiting the final result. |
| `delivered` | Done. `delivered_at` is set. |
| `failed` | Delivery failed. PekanGame may retry; a wallet refund is a separate later action. |
| `needs_review` | The outcome is ambiguous and being confirmed manually. Rare. |

`delivered` is final. `failed` is usually final too, but PekanGame may
re-attempt a stuck order, so a `failed` order can still reach `delivered`
later (you get an `order.delivered` webhook if so). Poll or use the webhook
until the order is `delivered` or `failed`.
For a failed order, check `wallet_refunded` separately to learn whether the
wallet has actually been credited. Do not infer a refund from `failed` alone.

## 4. Get the result

Delivery is asynchronous. Two ways to learn the outcome:

- **Poll** `GET /v1/orders/{order_number}` until `delivery_status`
  is `delivered` or `failed`.
- **Webhook** — register an endpoint once and receive a signed `order.delivered`
  / `order.failed` callback. See [Delivery notifications](/webhooks/).

```http
GET /v1/orders/PG-7QK2M9X4RJ
```

```json
{
  "order_number": "PG-7QK2M9X4RJ",
  "product_code": "MLMY-86",
  "player_id": "123456789",
  "server_id": "2201",
  "price_sen": 6300,
  "payment_status": "paid",
  "delivery_status": "delivered",
  "wallet_refunded": false,
  "wallet_refund": null,
  "created_at": "2026-09-10T09:14:52+00:00",
  "delivered_at": "2026-09-10T09:15:07+00:00"
}
```

A failed order can later be refunded to your wallet after PekanGame decides
not to retry it. In that case the order still reports `payment_status: paid`
and `delivery_status: failed`, but `wallet_refunded` becomes `true` and
`wallet_refund` supplies the actual credited amount and time. See
[Wallet & balance](/wallet/).
