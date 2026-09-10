---
title: Errors
description: The full error catalogue — every 4xx code, what it means, and what to do.
---

Every error response has the same shape:

```json
{ "error": "STABLE_CODE", "message": "Human-readable explanation." }
```

Branch on **`error`** (a stable machine code) and the **HTTP status** — never on
`message`, which is prose and may change.

`VALIDATION_FAILED` responses carry an extra `details` object:

```json
{
  "error": "VALIDATION_FAILED",
  "message": "The request payload failed validation.",
  "details": { "player_id": ["The player id field is required."] }
}
```

## Catalogue

| Status | `error` | Meaning | What to do |
| --- | --- | --- | --- |
| 401 | `MISSING_API_KEY` | No `Authorization: Bearer` header. | Send the key. |
| 401 | `INVALID_API_KEY` | The key is unknown or revoked. | Check the key; rotate if needed. |
| 403 | `RESELLER_INACTIVE` | The account is deactivated. | Contact PekanGame. |
| 403 | `IP_NOT_ALLOWED` | This key has an IP allowlist and your address is not on it. | Add the IP in the portal, or clear the allowlist. |
| 404 | `ORDER_NOT_FOUND` | No order with that number belongs to you. | Check the `order_number`. |
| 404 | `NOT_FOUND` | The endpoint path does not exist. | Check the URL against the reference. |
| 405 | `METHOD_NOT_ALLOWED` | Wrong HTTP method for that path. | Check the reference. |
| 409 | `IDEMPOTENCY_KEY_CONFLICT` | The `idempotency_key` was already used with a different payload. | Use a fresh UUID. |
| 422 | `VALIDATION_FAILED` | A field is missing or malformed. See `details`. | Fix the payload. |
| 422 | `UNKNOWN_PRODUCT_CODE` | The `product_code` is unknown or unavailable. | Re-read `GET /v1/catalog`. |
| 422 | `NO_TIER_ASSIGNED` | Your account has no pricing configured yet. | Contact PekanGame. |
| 422 | `INSUFFICIENT_BALANCE` | The order would overdraw the wallet. | Top up; retry with a fresh key. |
| 429 | `RATE_LIMITED` | Too many requests. | Wait for the `Retry-After` header, then retry. |

## 5xx

A `500`/`502`/`503` is a transient PekanGame-side problem. For `POST /v1/orders`,
**retry with the same `idempotency_key`** (see [Idempotency & retries](/idempotency/)).
For reads, retry with backoff.
