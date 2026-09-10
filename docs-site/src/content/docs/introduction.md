---
title: Introduction
description: What the PekanGame Reseller API is, who it is for, and how to get access.
---

The Reseller API lets your own system place game top-up orders against a
**prepaid PekanGame wallet**. You top the wallet up; every order is charged to
it at your account's price; PekanGame fulfils the order and reports the outcome
back to you.

It is a small REST API — five endpoints and an optional delivery webhook. JSON
in, JSON out, one bearer key.

## Who it is for

A reseller account is **prepaid and spend-only** — you place orders against a
balance you have topped up. If you resell PekanGame top-ups through your own
shop, bot, or website and want to automate ordering, this API is for you.

## Base URL

```
https://api.pekangame.space/api/reseller
```

Every endpoint path in this documentation (`/v1/balance`, `/v1/orders`, …) is
relative to that base. There is no separate sandbox host — contact PekanGame if
you need test credentials.

## Access is invite-only

There is no public sign-up form. PekanGame:

1. Creates your reseller account and sets your pricing.
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
- **Anything that is not yours** — the API only ever returns your own account's
  data and your own prices. It never exposes another reseller's orders, or how
  a price is composed upstream.
