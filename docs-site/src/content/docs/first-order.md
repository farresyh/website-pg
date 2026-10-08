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
      "checkout_input": { "field": "server_id", "options": null, "player_id_format": "numeric" },
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

`checkout_input` is each game's input contract. Check it programmatically
rather than hardcoding per game:

| Key | Value | What to send |
| --- | --- | --- |
| `player_id_format` | `numeric` | `player_id` is digits only |
| | `text` | `player_id` is letters, digits and `# . _ -` (e.g. a Riot ID, `JettMain#1234`) |
| `field` | `null` | **no** `server_id` — sending one is rejected |
| | `server_id` | `server_id` is required, digits only |
| | `zone_id` | `server_id` is required and must be one of `options` |

The request field is always named `server_id`, even for a zone. `options` is
the exact picklist when present; a zone game with `options: null` accepts any
value. No value may contain spaces, and each is at most 64 characters. A value
that breaks the contract gets a `422 VALIDATION_FAILED` naming the field —
nothing is charged.

## 3. Place the order

```http
POST /v1/orders
Content-Type: application/json

{
  "product_code": "MLMY-86",
  "player_id": "123456789",
  "server_id": "2201",
  "idempotency_key": "8f2b8c4e-1d3a-4a9c-9b1e-2f6a7c0d5e11",
  "max_price_sen": 6300
}
```

- `server_id` only when the game's `checkout_input.field` is not `null`
  (Mobile Legends needs one; many games don't).
- `idempotency_key` is a **fresh UUID you generate per order**. See
  [Idempotency & retries](/idempotency/).
- `max_price_sen` (optional, **recommended**) is the most you agree to pay,
  in sen — usually the `price_sen` you last read from the catalogue.

### Prices change — send `max_price_sen`

Your price follows the supplier's cost, which PekanGame syncs every 30
minutes, so a `price_sen` you read earlier may no longer be current. You are
always charged the **current** price at the moment of the order:

- current price ≤ `max_price_sen` → the order goes through at the current
  price (lower, if it has dropped);
- current price > `max_price_sen` → `422 PRICE_CHANGED`, nothing is charged,
  and `details.current_price_sen` tells you the new price;
- no `max_price_sen` → the order goes through at whatever the current price
  is.

Don't retry a `PRICE_CHANGED` automatically at `current_price_sen` — that is
the same as not sending a ceiling. Check the new price against your own margin
first.

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
| `partially_delivered` | Part of the order was delivered and the rest failed. The undelivered part is refunded to your wallet as a separate later action. Rare. |

`delivered` is final. `failed` and `partially_delivered` are usually final
too, but PekanGame may re-attempt the part that failed, so either can still
reach `delivered` later (you get an `order.delivered` webhook if so). Poll or
use the webhook until the order is `delivered`, `failed` or
`partially_delivered`.
For a failed or partially delivered order, check `wallet_refunded` separately
to learn whether the wallet has actually been credited, and
`wallet_refund.amount_sen` for how much — for a partial delivery it is only
the undelivered share. Do not infer a refund from the status alone.

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
