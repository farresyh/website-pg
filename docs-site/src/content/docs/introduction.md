---
title: Introduction
description: What the PekanGame Reseller API is, who it is for, and how to get access.
---

The Reseller API lets your own system place game top-up orders against a
**prepaid PekanGame wallet**. You top the wallet up; every order is charged to
it at your wholesale tier price; PekanGame fulfils the order with the supplier
and reports the outcome back to you.

It is a small REST API — four read/write endpoints plus an order-history list
and an optional delivery webhook. JSON in, JSON out, one bearer key.

## Who it is for

A reseller account is a **spend-only, prepaid** account. It never earns a
margin or requests a payout — that is a separate product (the Affiliate
whitelabel storefront). If you resell PekanGame top-ups through your own shop,
bot, or website and want to automate ordering, this API is for you.

## Base URL

```
https://api.pekangame.space/api/reseller/v1
```

Every path in this documentation is relative to that base. There is no separate
sandbox host — talk to PekanGame if you need test credentials.

## Access is invite-only

There is no public sign-up form. PekanGame:

1. Creates your reseller account and assigns your wholesale tier.
2. Issues your first API key (a `pgrk_` token, shown once).
3. Gives you a login to the partner portal, where you manage keys, the webhook,
   and wallet top-ups yourself.

<a id="request-access"></a>

### Request access

Email **partners@pekangame.space** with your business name, the platform you
sell through, and your expected monthly volume.

## What is out of scope

- **Player-ID validation** — there is no "check this player ID" endpoint.
  Validation happens when the order is placed; an invalid ID comes back as a
  failed order.
- **Cash refunds** — a failed order that is not retried is refunded to your
  **wallet**, never to a card or bank account.
- **Another reseller's data, supplier identities, your cost or PekanGame's
  margin** — none of these ever appear in an API response.
