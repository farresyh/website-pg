---
title: Purchase Balance
description: The prepaid model — adding balance, reading your balance, limits, and how refunds work.
---

A reseller account is **prepaid**. There is no credit line and no invoice.
Every order is an immediate debit from your Purchase Balance.

The Purchase Balance can only be used to buy on PekanGame. It cannot be
withdrawn, cashed out or transferred to another account.

## Reading your balance

```http
GET /v1/balance
```

```json
{ "balance_sen": 125000 }
```

`balance_sen` is in **sen** (RM 1.00 = `100`). It is the single source of
truth — it is derived from a ledger, not a mutable counter.

## Adding balance

Balance is added from the **partner portal** or the WhatsApp bot, not the API:

- **Self-serve** — pay by FPX through the portal's Purchase Balance page (or the
  WhatsApp bot's `.topupbaki` command). The balance updates once payment clears,
  and a Payment Receipt is available to download.
- **Manual** — arrange a bank transfer with PekanGame; support credits the
  Purchase Balance against your receipt.

## Insufficient balance

If an order would take the balance below zero, `POST /v1/orders` returns
`422 INSUFFICIENT_BALANCE` and **nothing is charged**. Add balance and retry with a
**fresh** `idempotency_key` (the rejected attempt created no order).

## Limits

Each payment is limited to **RM 10,000**, and a Purchase Balance can hold up
to **RM 100,000** unless PekanGame has agreed a different limit for your
account. Your portal shows the exact figures. A payment above a limit is not
accepted.

## Refunds

A failed order is not refunded to a card or bank account. A failed delivery is
first reviewed for retry. If PekanGame decides not to retry, an admin refunds it
**to your Purchase Balance**: the whole charged amount for a `failed` order, or only the
undelivered share for a `partially_delivered` one (part of the order was
delivered and the rest failed). Neither status by itself is proof of a refund.

When it happens you receive an `order.refunded` webhook (if you have one
registered), and the amount reappears in `balance_sen`. Order responses from
`GET /v1/orders/{order_number}` and `GET /v1/orders` also show
`wallet_refunded: true` and a `wallet_refund` object containing the ledger's
`amount_sen` (what was actually credited) and `refunded_at`. Without a
webhook, check those fields to reconcile; the delivery status stays `failed`
or `partially_delivered` after the refund.

Unused balance is refunded to the original payer only if your account is
closed.

## Pricing

The `price_sen` on every catalogue row and every order is **your price** for
that package, in sen. The price you see in `GET /v1/catalog` and the amount
debited when you place the order are computed the same way, so they cannot
drift. Package prices can change at any time, so re-read the catalogue and
send `max_price_sen` with each order — see
[Prices change](/first-order/#prices-change--send-max_price_sen).
