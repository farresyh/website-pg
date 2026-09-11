---
title: Wallet & balance
description: The prepaid model — topping up, reading your balance, and how refunds work.
---

A reseller account is **prepaid**. There is no credit line and no invoice.
Every order is an immediate debit from your wallet balance.

## Reading your balance

```http
GET /api/reseller/v1/balance
```

```json
{ "balance_sen": 125000 }
```

`balance_sen` is in **sen** (RM 1.00 = `100`). It is the single source of
truth — it is derived from a ledger, not a mutable counter.

## Topping up

Top-ups are done from the **partner portal**, not the API:

- **Self-serve** — pay by FPX / card through the portal's wallet page. The
  balance updates once payment clears.
- **Manual** — arrange a bank transfer with PekanGame; support credits the
  wallet against your receipt.

## Insufficient balance

If an order would take the balance below zero, `POST /v1/orders` returns
`422 INSUFFICIENT_BALANCE` and **nothing is charged**. Top up and retry with a
**fresh** `idempotency_key` (the rejected attempt created no order).

## Refunds

PekanGame does not do cash refunds. A failed order is refunded **to your
wallet**:

- Automatically, once the order is no longer being retried.
- On request, via support, for an order that is stuck.

When it happens you receive an `order.refunded` webhook (if you have one
registered), and the amount reappears in `balance_sen`. The refund is always
the exact `price_sen` you were charged.

## Pricing

The `price_sen` on every catalogue row and every order is **your price** for
that package, in sen. The price you see in `GET /v1/catalog` and the amount
debited when you place the order are computed the same way, so they cannot
drift. If your pricing changes, PekanGame will tell you in advance.
