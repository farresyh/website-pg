---
title: Rate limits
description: The request rate limit and how to handle a 429.
---

The API is rate-limited **per API key** at **60 requests per minute**.

The limit is keyed on the key itself, not on your IP — a reseller behind shared
infrastructure or NAT is never penalised for a neighbour's traffic.

## Hitting the limit

When you exceed it you get:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 30

{ "error": "RATE_LIMITED", "message": "Too many requests. Retry after the period given in the Retry-After header." }
```

Wait for the number of seconds in **`Retry-After`**, then retry. Do not retry
sooner — it will keep failing and does not reset the window.

## Staying under it

- Cache the catalogue for a few minutes rather than fetching it per order.
- Prefer the [delivery webhook](/webhooks/) over tight polling loops. If you do
  poll an order, back off (for example 2s, 5s, 10s, 30s).
- Use `GET /v1/orders` with `?created_after=` to reconcile many orders in one
  request instead of fetching them individually.

If 60/min is genuinely too low for your volume, contact PekanGame.
