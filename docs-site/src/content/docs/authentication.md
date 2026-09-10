---
title: Authentication
description: Bearer API keys — how to send them, rotate them, scope them to an IP, and recover from a leak.
---

Every request is authenticated with a single **bearer API key**:

```http
GET /v1/balance HTTP/1.1
Host: api.pekangame.space
Authorization: Bearer pgrk_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX
```

The full request URL is the [base URL](/introduction/#base-url) plus the path —
`https://api.pekangame.space/api/reseller/v1/balance`.

There is no request signing and no second secret. One strong opaque token is
the whole credential — keep it server-side, never ship it in a browser or app.

## Keys

- A key looks like `pgrk_` followed by 48 random characters.
- The **full key is shown once**, when it is issued. PekanGame stores only a
  hash — nobody can recover it later, so copy it immediately.
- You can hold **up to 5 active keys** per account. Use separate keys per
  integration or environment so you can revoke one without disrupting the rest.
- Manage keys yourself in the partner portal under **API Keys**, or ask
  PekanGame support.

### Rotating a key

1. Issue a new key in the portal.
2. Deploy it to your system.
3. Revoke the old key.

Revocation is immediate. A revoked key returns `401 INVALID_API_KEY`.

## IP allowlist (optional)

Each key can carry a list of **allowed IP addresses**. When the list is
non-empty, a request from any other address is rejected with
`403 IP_NOT_ALLOWED` — and the attempt still records the source IP, so you can
spot it.

- An **empty list means "any IP"** — this is the default, so a serverless or
  shared-infra integration keeps working with no configuration.
- Matching is **exact** (no CIDR ranges yet).
- Set it per key in the portal. The portal also shows each key's
  **last-used IP**, so you can confirm traffic is coming from where you expect.

We recommend an allowlist for any fixed server-to-server integration.

## If a key leaks

1. **Revoke it** in the portal immediately — this stops all use of it.
2. Issue a replacement and deploy it.
3. Check your recent orders (`GET /v1/orders`) and the webhook delivery log for
   anything you did not place. The maximum exposure is your wallet balance;
   there is no way to move money out of the account with the API.
