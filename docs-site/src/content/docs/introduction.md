---
title: Introduction
description: What the PekanGame Reseller API is, who it is for, and how to get access.
---

The Reseller API lets your own system place game top-up orders against a
**prepaid PekanGame Purchase Balance**. You add balance to it; every order is charged to
it at your account's price; PekanGame fulfils the order and reports the outcome
back to you.

It is a small REST API — five endpoints and an optional delivery webhook. JSON
in, JSON out, one bearer key.

## Who it is for

A reseller account is **prepaid** — you place orders against a balance you have
added, and the balance is never withdrawn as cash. If you resell PekanGame game top-ups through your own
shop, bot, or website and want to automate ordering, this API is for you.

## Base URL

```
https://api.pekangame.space/api/reseller
```

Every endpoint path in this documentation (`/v1/balance`, `/v1/orders`, …) is
relative to that base. There is no sandbox and no test key yet: every order
is real and is charged to your Purchase Balance. Start with one low-value
order.

The machine-readable OpenAPI 3.1 spec is at
[`/openapi.json`](/openapi.json), for generating a client or importing into
Postman. It is generated from the API's code, so it matches what the server
sends.

## Access is by application

You cannot sign yourself up: you apply and PekanGame reviews your application.
If it is approved, PekanGame:

1. Creates your reseller account and sets your pricing.
2. Issues your first API key (a `pgrk_` token, shown once).
3. Gives you a login to the partner portal, where you manage keys, the webhook,
   and adding balance yourself.

<a id="request-access"></a>

### Request access

Apply at **[onboarding.pekangame.space/apply](https://onboarding.pekangame.space/apply)**
with your business name, the platform you sell through, and your expected
monthly volume. PekanGame will email you the next step.

## What is out of scope

- **Player-ID validation** — there is no "check this player ID" endpoint.
  Validation happens when the order is placed; an invalid ID comes back as a
  failed order.
- **Cash refunds** — a failed order is refunded to your **Purchase Balance**
  ([Refunds](/wallet/#refunds)), never to a card or bank account. Unused balance is refunded
  only if the account is closed.
- **Anything that is not yours** — the API only ever returns your own account's
  data and your own prices. It never exposes another reseller's orders, or how
  a price is composed upstream.
