---
title: Product codes
description: How to build the product_code you order against.
---

You order against a **product code**, not an internal ID. A code is:

```
{game code}-{denomination}
```

for example `MLMY-86` — 86 diamonds for Mobile Legends (Malaysia). For a
package that has no numeric denomination, the second half is a short catalogue
code instead (`{game code}-{catalog code}`).

## Discover codes from the catalogue

Never hard-code a code you have not seen in `GET /v1/catalog`. The catalogue
gives you both halves already joined:

```json
{
  "games": [
    {
      "code": "MLMY",
      "name": "Mobile Legends (Malaysia)",
      "packages": [
        { "code": "MLMY-14", "name": "14 Diamonds", "price_sen": 1200 },
        { "code": "MLMY-86", "name": "86 Diamonds", "price_sen": 6300 }
      ]
    }
  ]
}
```

Use `packages[].code` verbatim as the `product_code` in
`POST /v1/orders`.

## Rules

- Codes are **case-sensitive** and at most 32 characters.
- The set of codes and their prices can change — re-read the catalogue
  regularly rather than caching it indefinitely. It is cached server-side, so
  polling it every few minutes is fine.
- An unknown or currently-unavailable code returns
  `422 UNKNOWN_PRODUCT_CODE`.
- The `game code` is stable; PekanGame will not rename one without notice.
