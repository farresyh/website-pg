---
title: Rate limits
description: The request rate limits and how to handle a 429.
---

The API is rate-limited **per reseller account**, in two separate buckets:

| Bucket | Endpoints | Limit |
| --- | --- | --- |
| Orders | `POST /v1/orders` | 60 requests per minute |
| Reads | `GET /v1/catalog`, `GET /v1/balance`, `GET /v1/orders`, `GET /v1/orders/{order_number}` | 120 requests per minute |

- **Every API key on your account shares these limits.** Issuing another key
  does not add capacity; use separate keys to separate integrations, not to
  raise the limit.
- The buckets are independent: polling orders or reading the catalogue never
  uses up your order bucket, and placing orders never blocks your reads.
- The limits are keyed on your account, not your IP, so a reseller behind
  shared infrastructure or NAT is never penalised for a neighbour's traffic.
- Every authenticated request counts, including one rejected as invalid
  (`409`, `422`) and a [replayed order](/idempotency/).

Separately, requests that fail authentication are limited per IP (20 per
minute), then return `429 RATE_LIMITED`. A valid key is never blocked by this.

## Headers

Every response to an authenticated request carries the state of the bucket
it used:

| Header | Value |
| --- | --- |
| `X-RateLimit-Limit` | The bucket's limit per minute. |
| `X-RateLimit-Remaining` | Requests left in the current window. |

## Hitting the limit

When you exceed it you get:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 30

{ "error": "RATE_LIMITED", "message": "Too many requests. Retry after the period given in the Retry-After header." }
```

Wait for the number of seconds in **`Retry-After`**, then retry. Do not retry
sooner — it will keep failing and does not reset the window. A `429` on
`POST /v1/orders` means the order was not placed; retry it with the **same**
`idempotency_key`.

## Staying under it

- Cache the catalogue for a few minutes rather than fetching it per order.
- Prefer the [delivery webhook](/webhooks/) over tight polling loops. If you do
  poll an order, back off (for example 2s, 5s, 10s, 30s).
- Use `GET /v1/orders` with `?created_after=` to reconcile many orders in one
  request instead of fetching them individually.

If these limits are genuinely too low for your volume, contact PekanGame.
