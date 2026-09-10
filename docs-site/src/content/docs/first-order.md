---
title: Your first order
description: An end-to-end walkthrough — read the catalogue, place an order, and get the result.
---

This walkthrough places one order from start to finish. Every request needs the
`Authorization: Bearer` header from [Authentication](/authentication/); it is
omitted below for brevity.

## 1. Check your balance

```http
GET /api/reseller/v1/balance
```

```json
{ "balance_sen": 125000 }
```

All money is in **sen** (RM 1.00 = `100`). `125000` is RM 1,250.00.

## 2. Read the catalogue

```http
GET /api/reseller/v1/catalog
```

```json
{
  "games": [
    {
      "code": "MLMY",
      "name": "Mobile Legends (Malaysia)",
      "packages": [
        { "code": "MLMY-14", "name": "14 Diamonds", "price_sen": 1200 },
        { "code": "MLMY-86", "name": "86 Diamonds", "price_sen": 6300 }
      ]
    }
  ]
}
```

`price_sen` is **your** tier price, markup already applied. Order against
`packages[].code` — see [Product codes](/product-codes/).

## 3. Place the order

```http
POST /api/reseller/v1/orders
Content-Type: application/json

{
  "product_code": "MLMY-86",
  "player_id": "123456789",
  "server_id": "2201",
  "idempotency_key": "8f2b8c4e-1d3a-4a9c-9b1e-2f6a7c0d5e11"
}
```

- `server_id` is required only for games that use one (Mobile Legends does;
  many do not).
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
  "created_at": "2026-09-10T09:14:52+00:00",
  "delivered_at": null
}
```

Your wallet is charged `price_sen` at this moment. `payment_status` is `paid`
straight away (it is a wallet debit). `delivery_status` starts at
`not_started`, advances to `processing` while PekanGame submits it to the
supplier, then settles on `delivered` or `failed` (occasionally
`needs_review` — a human checks it).

## 4. Get the result

Delivery is asynchronous. Two ways to learn the outcome:

- **Poll** `GET /api/reseller/v1/orders/{order_number}` until `delivery_status`
  is `delivered` or `failed`.
- **Webhook** — register an endpoint once and receive a signed `order.delivered`
  / `order.failed` callback. See [Delivery notifications](/webhooks/).

```http
GET /api/reseller/v1/orders/PG-7QK2M9X4RJ
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
  "created_at": "2026-09-10T09:14:52+00:00",
  "delivered_at": "2026-09-10T09:15:07+00:00"
}
```

A `failed` order is refunded to your wallet (automatically once PekanGame stops
retrying it, or on request). See [Wallet & balance](/wallet/).
