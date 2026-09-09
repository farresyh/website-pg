# Payment Gateway Research — CHIP vs HitPay (2026-07-30)

> **OUTCOME (historical):** This fed **ADR-022** — CHIP was chosen, added alongside Xendit,
> then made the **sole gateway 2026-09-01** (Xendit removed entirely, code archived). CHIP FPX
> has been live in production with real money since 2026-09-03. This file is kept as the
> primary-source rate/API research that informed that call; it is **not an open question**.

**Purpose (as written 2026-07-30):** input to a follow-up ADR on whether to swap away from
Xendit (ADR-001) or run a second gateway concurrently through the existing
`PaymentGatewayFactory` / `payment_methods.gateway` seam (ADR-001 addendum, 2026-07-25).

**Method:** every claim below is cited to a primary source — the gateway's own docs site, its own
published rate card, or its own OpenAPI spec. Secondary sources (aggregator comparisons, third-party
blogs) were deliberately **not** used as evidence. Where a fact could not be verified against a
primary source, it is marked **UNVERIFIED** rather than inferred. See §3 for the gap list.

---

## 0. Xendit baseline (from ADR-001, for comparison)

Recorded here only so the two candidate sections can be read against it — not re-researched this pass.

| Dimension | Xendit (per ADR-001) |
| --- | --- |
| Channels in MY | FPX, DuitNow QR, cards, GrabPay (`AMBANK_FPX`, `GRABPAY` verified live in sandbox) |
| Auth | Secret API key, HTTP Basic |
| Webhook verification | **Plain shared-token comparison** (`x-callback-token`), not a signature |
| Idempotency key on create | **No** — Payment Request v3 has no idempotency header; server-side `reference_id` uniqueness is the actual dedupe mechanism |
| Split / sub-merchant | **xenPlatform** — OWNED (invisible to reseller, minimal KYC, platform pushes payout via Disbursement API with `for-user-id`) vs MANAGED (own dashboard + own KYC) |
| Fee rates | **Now published and recorded** — see §0.1. Item #10 in §4 (originally "not in the repo") is closed as of this same session. |

### 0.1 Xendit's real published MY rate card (closed the "no numbers in the repo" gap, 2026-07-30)

Fetched live from `xendit.co/en/pricing` and `xendit.co/en-my/malaysia/` this same session (a separate
but directly related task — the founder's `payment_methods` seeded defaults turned out to be stale
placeholders, not these real figures; fixed in `PaymentMethodSeeder`, see `docs/prd.md` §14's
2026-07-30 entry). List price — Xendit's own disclaimer notes actual contracted rates may differ by
merchant agreement/volume, so treat this as the public ceiling, not a confirmed contract rate.

| Channel | Rate |
| --- | --- |
| FPX (personal) | RM1.20 + RM0.90 = **RM2.10 flat** |
| FPX (business) | RM2.00 + RM0.90 = **RM2.90 flat** |
| DuitNow (online banking) | RM2.00 + RM0.90 = **RM2.90 flat** |
| GrabPay | **2.00%** + RM0.90 |
| Touch 'n Go (local) | **1.80%** + RM0.90 |
| Touch 'n Go (foreign) | 2.50% + RM0.90 |
| ShopeePay | **2.50%** + RM0.90 |
| WeChat Pay | **2.50%** + RM0.90 |
| Alipay | 2.50% + RM0.90 |
| Alipay+ | 3.00% + RM0.90 |
| Domestic debit card | 1.90% + RM0.90 |
| Domestic credit card | 2.00% + RM0.90 |
| International card | **3.80%** + RM0.90 |
| Grab PayLater (postpaid) | 6.00% + RM0.90 |
| Grab PayLater (4-mo instalment) | 8.00% + RM0.90 |
| SPayLater | 2.5% + RM0.90 |
| Virtual account | 0.50% (min RM1.00) + RM0.90 |
| Payouts | 1.00% (min RM1.50) + RM0.90 |

---

## 1. CHIP (chip-in.asia)

### 1.1 Identity & disambiguation

**Conclusion: "ChipIn Asia" means CHIP, at `chip-in.asia`, docs at `docs.chip-in.asia`.**

Disambiguation performed explicitly, because the brief flagged real ambiguity:

- **`chip-in.asia`** — the Malaysian payment gateway. Marketing site `www.chip-in.asia`, merchant
  portal `portal.chip-in.asia`, docs `docs.chip-in.asia`, API host `gate.chip-in.asia`, GitHub org
  [`github.com/CHIPAsia`](https://github.com/CHIPAsia). This is the product meant.
- **`getchip.co`** — **domain does not resolve** (DNS `ENOTFOUND` on fetch, 2026-07-30). Whatever it
  once was, it is not a live product today and is not the referent. There is a UK consumer
  savings/investment app historically branded "Chip" — unrelated to payments acquiring, and not
  reachable at that domain.
- The brand is written "CHIP" (all caps) in its own docs, and "CHIP IN" in regulatory contexts. Its
  two API products are named **CHIP Collect** (accept payments) and **CHIP Send** (payouts).

Two distinct product lines matter for this evaluation:
[CHIP Collect API](https://docs.chip-in.asia/chip-collect/overview/introduction) and
[CHIP Send API](https://docs.chip-in.asia/chip-send/api-reference/introduction).

### 1.2 Malaysia licensing & channel support

**Licensed in Malaysia specifically — not merely "available in SEA".** CHIP is approved by **Bank
Negara Malaysia as a Non-Bank Merchant Acquirer**; incorporated March 2022, PCI-DSS Level 1,
ISO/IEC 27001:2022 and 27017:2015 certified.
Source: [fintechnews.my — CHIP IN Approved by BNM as Non-Bank Merchant Acquirer](https://fintechnews.my/45780/payments-remittance-malaysia/chip-in-approved-by-bnm-as-non-bank-merchant-acquirer/)
— note this is a trade-press source, not CHIP's own docs; the BNM approval itself is a regulatory
fact worth re-confirming against BNM's own public register before the ADR is marked Accepted.

Channels, taken from the `payment_method` enum in CHIP's own OpenAPI spec
([`docs.chip-in.asia/openapi/chip-collect.yaml`](https://docs.chip-in.asia/openapi/chip-collect.yaml)):

- **FPX:** `fpx` (B2C), `fpx_b2b1` (corporate)
- **DuitNow QR:** `duitnow_qr`, `dnqr`
- **E-wallets:** `razer_tng` (Touch 'n Go), `razer_grabpay` (GrabPay), `razer_shopeepay` (ShopeePay)
- **Cards:** `visa`, `mastercard`, `maestro`; wallets `mpgs_apple_pay`, `mpgs_google_pay`
- **BNPL:** `razer_atome`
- **Crypto:** `crypto_coin`

**Boost is not present in the enum.** Third-party/marketing pages claim Boost support; it does not
appear in the machine-readable spec, so treat Boost as **UNVERIFIED**. Note also that TnG/GrabPay/
ShopeePay/Atome are all prefixed `razer_` — CHIP appears to reach those wallets *through Razer
Merchant Services* rather than directly, which is worth knowing for settlement/reliability reasons
even though it doesn't change the API surface.

### 1.3 Fee rates — published, and the headline finding

CHIP publishes a real per-channel rate card at
[www.chip-in.asia/collect](https://www.chip-in.asia/collect):

| Channel | Fee | Settlement |
| --- | --- | --- |
| **FPX B2C** | **RM1 flat** | Next day |
| **FPX B2B1** | **RM2 flat** | Next day |
| DuitNow QR (Online) | 1.00%, min RM0.15 | Next day |
| DuitNow QR (physical, "CHIP mini") | 1.00%, min RM0.15 | Next day |
| All e-wallets (TnG, GrabPay, ShopeePay, Maybank QR Pay) | 1.40% | 2 business days |
| Local credit card | 2.0% | 2 business days |
| Local debit card | 1.0% | 2 business days |
| Foreign credit/debit card | 3.0% | 2 business days |
| Atome (BNPL) | 5.3% | Following Thursday |
| Stablecoin (BTC/ETH/PYUSD/USDC/USDT) | 1.50% (+ gas/network) | 1 business day |

No setup fee and no monthly fee listed.

**Why FPX at a flat RM1 is the single most important number in this document — corrected against the
real Xendit baseline (§0.1, closed same session):** the comparison below originally assumed Xendit's
FPX was percentage-based; it is not. **Xendit's real FPX rate is also flat — RM2.10 (personal) / RM2.90
(business)** (xendit.co/en/pricing). Comparing flat-to-flat removes the order-value dependency
entirely: **CHIP's RM1 FPX (B2C) is unconditionally cheaper than Xendit's RM2.10 by RM1.10 per
transaction, regardless of basket size** — no breakeven point, no "only wins on large orders" caveat.
For business-registered FPX specifically the gap is even larger (RM2 vs RM2.90, RM0.90/txn). Given FPX
is very likely this storefront's dominant channel (Malaysian guest checkout, small MLBB top-up
baskets), this is a real, basket-size-independent saving — the strongest single fact in this document
for CHIP's case, not a directional "depends on your average order" one as first drafted.

### 1.4 API shape

- **REST, confirmed.** Base URL `https://gate.chip-in.asia/api/v1/` — all endpoints share that
  prefix. Create-payment is `POST /purchases/`.
  ([introduction](https://docs.chip-in.asia/chip-collect/overview/introduction),
  [purchases/create](https://docs.chip-in.asia/chip-collect/api-reference/purchases/create))
- **Auth (Collect):** bearer secret key — `Authorization: Bearer <secret key>`. Keys issued at
  `portal.chip-in.asia/collect/developers/api-keys`.
  ([authentication](https://docs.chip-in.asia/chip-collect/overview/authentication))
- **Auth (Send) is different and heavier:** API key **plus HMAC-SHA512 request signing** —
  `checksum = HEX( HMAC_SHA512( key = API Secret, message = signing_string ) )`, sent alongside a
  bearer token and an epoch-timestamp header.
  ([CHIP Send introduction](https://docs.chip-in.asia/chip-send/api-reference/introduction))
- **Test mode: yes, but it is key-switched, not host-switched.** "The merchant portal issues separate
  keys for **test mode** and **live mode**. Test keys only work against test purchases and never move
  real money." There is **no separate sandbox base URL** — test and live both hit
  `gate.chip-in.asia`. ([authentication](https://docs.chip-in.asia/chip-collect/overview/authentication))
  This is a meaningful operational difference from both Xendit and HitPay: config safety rests
  entirely on which key is loaded, with no hostname to catch a mistake.
- **Notable request fields** on `POST /purchases/`: `brand_id` (UUID, required), `client`/`client_id`
  (required — a customer object is mandatory, mirroring the same constraint that forced
  `customer_name` into checkout for Xendit per ADR-001's 2026-07-25 addendum), `purchase`,
  `reference` (max 128 chars, "Invoice reference"), `success_callback`, `success_redirect`,
  `failure_redirect`, `cancel_redirect`, `payment_method_whitelist`, `skip_capture`,
  `force_recurring`, `send_receipt`.
- **Official PHP SDK exists** ([github.com/CHIPAsia](https://github.com/CHIPAsia)) — not evaluated
  for quality this pass; the `PaymentGateway` seam means an SDK is optional either way.

### 1.5 Webhook / callback model — **RSA, not HMAC** (highest integration cost finding)

Two delivery paths, both `POST`:

1. **Per-purchase `success_callback`** — URL supplied on the create call; "the `success_callback` URL
   will receive a POST request with the Purchase object's data in body."
2. **Registered webhooks** — event-subscription model with 25+ event types including
   `purchase.created`, `purchase.paid`, `purchase.payment_failure`, `purchase.pending_capture`,
   `purchase.pending_refund`, `payment.refunded`, `payout.pending`, `payout.success`, `payout.failed`.

**Verification mechanism, quoted from the OpenAPI spec:** "Each callback delivery request includes an
`X-Signature` header field. This field contains a **base64-encoded RSA PKCS#1 v1.5 signature of the
SHA256 digest of the request body buffer**."
([chip-collect.yaml](https://docs.chip-in.asia/openapi/chip-collect.yaml),
[authentication](https://docs.chip-in.asia/chip-collect/overview/authentication))

Public key retrieval differs by path: for `success_callback` it is `GET /public_key/` (returns a
PEM-encoded RSA public key); for registered webhooks the key is on the `Webhook.public_key` field of
the webhook object itself.

**Integration-cost read:** this is asymmetric public-key crypto — a genuinely different shape from
Xendit's plain token comparison, and from HitPay's symmetric HMAC. In PHP it is
`openssl_verify($rawBody, base64_decode($sig), $pemPublicKey, OPENSSL_ALGO_SHA256)`, so it is not
*hard*, but it is more moving parts than either alternative: the raw body must be captured pre-JSON-
parse, and the PEM public key must be fetched and cached (with a plan for rotation). Per ADR-001's
own consequence note, webhook handling is the one place a second gateway genuinely costs new code —
CHIP is the more expensive of the two candidates on exactly that axis.

### 1.6 Split-payment / sub-merchant / marketplace

**No xenPlatform equivalent found in primary docs.** Specifically:

- `POST /purchases/` has **no** split, destination, sub-merchant, or platform-commission field. Its
  `brand_id` identifies which of *the merchant's own brands* the purchase belongs to — a
  multi-brand-under-one-account concept for businesses running several storefronts, **not**
  third-party sub-merchants with their own KYC or balances.
- **CHIP Send** is a payout API "to send funds programmatically via a REST API and to register
  recipient bank accounts for payouts" — merchant-level, disbursing from the merchant's own balance
  to registered third-party bank accounts. It has no sub-merchant/multi-tier concept in its docs.

So CHIP can mechanically reproduce the *Phase-2 reseller payout* behaviour ADR-001 designed against
xenPlatform OWNED sub-accounts (platform holds all funds; platform pushes payout to a
reseller-supplied bank account — which is exactly what the existing `Withdrawal` model's
`bank_name`/`bank_account_no`/`bank_account_holder` fields already store), but it does **not** offer
per-reseller ledger segregation at the gateway. Under CHIP, the ledger *has* to be ours alone.
Arguably that's a feature given ADR-002 already treats our own `ledger_entries` as the source of
truth — but it should be a recorded decision, not an accident.

**UNVERIFIED / worth a direct sales question:** whether CHIP offers any unpublished
marketplace/platform programme. Absence from public docs is not proof of absence from the product.

### 1.7 Idempotency key on payment creation

**No.** No idempotency header or field is documented on `POST /purchases/`, and none appears in the
OpenAPI spec. Same posture as Xendit — the dedupe mechanism would have to be our own
`reference` uniqueness, exactly as the codebase already does for Xendit's `reference_id`.

---

## 2. HitPay (hitpayapp.com)

### 2.1 Identity

Unambiguous: **HitPay**, marketing site `hitpayapp.com`, docs `docs.hitpayapp.com`, API hosts
`api.hit-pay.com` (production) and `api.sandbox.hit-pay.com` (sandbox). Note the hyphen difference
between the marketing domain (`hitpayapp.com`) and the API domain (`hit-pay.com`) — easy to
mistype in config. Singapore-headquartered, operating across SEA.

### 2.2 Malaysia licensing & channel support

**Licensed in Malaysia specifically.** HitPay's own docs state it holds a **"BNM Merchant Acquirer"**
licence and is a "Registered payment instrument issuer / system operator" for Malaysia.
([availability-by-market](https://docs.hitpayapp.com/getting-started/availability-by-market))

Channels for Malaysia, quoted from that page:

- **Local APMs:** "FPX, DuitNow Online Banking, DuitNow QR, Touch 'n Go eWallet, GrabPay, ShopeePay,
  MAE, Atome, SPayLater, Grab PayLater, WeChat Pay, Alipay"
- **Cards:** Visa, Mastercard, UnionPay
- **Cross-border QR:** a 13-scheme "Borderless QR" stack (PayNow SG, PromptPay TH, QRIS ID, etc.)
- **Recurring:** "Cards, TnG, ShopeePay, GrabPay, ZaloPay"
- **Payout:** "T + 2 Calendar Days" minimum from HitPay Balance, minimum payout "RM 5"

**Boost:** same situation as CHIP — **not** in HitPay's own Malaysia list. Third-party summaries
claim it; the primary source doesn't. **UNVERIFIED.**

Channel breadth is HitPay's clear win over CHIP: DuitNow Online Banking, MAE, WeChat Pay, Alipay,
SPayLater and Grab PayLater have no CHIP equivalent, and the Borderless QR stack would let foreign
customers pay with their home wallet.

### 2.3 Fee rates — **the critical gap in this research**

HitPay's published Malaysia rate card ([hitpayapp.com/my/pricing](https://hitpayapp.com/my/pricing))
does **not** publish per-method rates for FPX, DuitNow QR, DuitNow Online Banking, Boost, MAE, or
Atome. What it does publish:

| Item | Rate |
| --- | --- |
| Domestic cards (online) | 1.2% + RM1 |
| International cards | 3% + RM1 |
| Foreign-currency transactions | +2% |
| Cards, in-person | 1.4% (min RM0.30) |
| Cards, recurring billing | 1.2% + RM1 |
| Touch 'n Go (recurring) | 1.9% |
| GrabPay (recurring) | 2% |
| ShopeePay (recurring) | 2.2% |
| Business-software tools surcharge (invoicing, payment links, online store, POS, recurring) | **+0.2%** |
| Setup / monthly fees | None |

The page says only "Pricing depends on the payment method used" and points to a calculator / sales
contact for the rest.

**Explicitly flagged as NOT primary-source verified:** a figure of *DuitNow 1.2%, FPX 1.8% + RM0.40*
surfaced in web-search summaries attributed to HitPay marketing content. Fetching HitPay's own blog
page directly ([best-fpx-payment-gateway-malaysia](https://hitpayapp.com/blog/best-fpx-payment-gateway-malaysia))
found **no such figures** — that page only says "no monthly fee and no setup fee. Businesses pay per
transaction only" and redirects to the pricing page. **Do not put 1.8% + RM0.40 in an ADR.** It could
not be confirmed against any HitPay-controlled page and may be a search-summary artifact or a stale
/ other-market rate.

Note also the **+0.2% business-software surcharge**: it is unclear from the page whether a pure
API/checkout integration (which is what this project would build) escapes it or whether "payment
links / online store" is read broadly. That ambiguity is worth resolving before any fee comparison,
since 0.2% is material at this project's margins.

### 2.4 API shape

- **REST, confirmed.** Create-payment: `POST https://api.hit-pay.com/v1/payment-requests`
  (sandbox: `POST https://api.sandbox.hit-pay.com/v1/payment-requests`).
  ([create-request](https://docs.hitpayapp.com/apis/payment-request/create-request))
- **Auth:** single API-key header — `X-BUSINESS-API-KEY`. Simpler than Xendit's Basic auth and than
  CHIP Send's HMAC signing.
- **Sandbox: a real, fully separate environment** — separate host *and* separate dashboard
  (`dashboard.sandbox.hit-pay.com`). Self-serve signup with dummy business details, "no verification
  or approval" needed, keys from Settings → API Keys, and a Developers → Request Logs viewer.
  Caveats from the docs: sandbox and production are "completely separate accounts" and cannot be
  converted; sandbox keys only work against sandbox endpoints.
  ([sandbox](https://docs.hitpayapp.com/apis/guide/sandbox))
  **Important limitation for this project:** the documented sandbox-testable methods are cards,
  PayNow, ShopeePay, GrabPay/GrabPay PayLater, Atome, and DOKU QRIS — and the signup flow is
  described with Singapore as the business country. **FPX and DuitNow QR are not listed as
  sandbox-testable**, and the docs warn "not all payment methods available in production may be
  testable in sandbox." Since FPX is the channel that matters most here, that is a real
  pre-integration risk: it may not be possible to prove out the MY-critical channel before going
  live. Recall that ADR-001's Xendit work was validated precisely by hitting a real sandbox with
  `AMBANK_FPX` — the same proof may not be available for HitPay. **Needs direct confirmation.**
- **Notable request fields:** `amount` (0.3–999,999,999.99), `currency`, `reference_number`
  (max 255, "Arbitrary reference number that you can map to your internal reference number"),
  `payment_methods[]`, `webhook` (per-request callback URL), `email`, `name`, `phone`,
  `redirect_url`, `metadata` (string values, max 500 chars each).
- HitPay also ships a CLI, an "AI Agent Skills" doc, and a Claude Code plugin
  ([ai-skills](https://docs.hitpayapp.com/apis/guide/ai-skills),
  [claude-code-plugin](https://docs.hitpayapp.com/apis/guide/claude-code-plugin)) — potentially
  useful for building the adapter, not a factor in the decision itself.

### 2.5 Webhook model — HMAC-SHA256, cleanly documented

`POST` with a JSON body. Headers: `Hitpay-Signature` (the signature), `Hitpay-Event-Type`
(`created` | `updated`), `Hitpay-Event-Object` (`charge` | `payout` | `invoice` | `order` |
`transfer`), `User-Agent: HitPay v2.0`.
([events](https://docs.hitpayapp.com/apis/guide/events))

**Verification: HMAC-SHA256 of the raw request body**, keyed by a salt, compared constant-time. The
docs give a Node example using `crypto.createHmac('sha256', salt).update(payload).digest('hex')` and
`crypto.timingSafeEqual`.

**Two salts exist and choosing wrong silently fails** — worth pinning in the ADR:

- **Per-webhook salt** — each registered webhook endpoint has its own salt, shown in the dashboard
  under Developers → Webhooks. **This is the one to use for event webhooks.**
- **API-key salt (legacy)** — one salt per business API key, "used only for older payment-request
  callbacks and plugin integrations."

**Integration-cost read:** the cheapest of the three to implement. Symmetric HMAC over the raw body
is ~10 lines in a Laravel controller (`hash_hmac('sha256', $request->getContent(), $salt)` +
`hash_equals`), needs no key fetching or rotation handling, and is a well-trodden Laravel pattern.
Materially cheaper than CHIP's RSA path.

### 2.6 Split-payment / sub-merchant — **the closest xenPlatform analogue**

HitPay has a real platform/marketplace capability, called **"Platforms"**, driven by a **Platform
Key**. ([platform-apis](https://docs.hitpayapp.com/apis/guide/platform-apis))

How it works, per the docs: the account must be "enabled as a **Platform account**" (via
`support@hit-pay.com` — not self-serve), after which "passing it as an `X-PLATFORM-KEY` header on
payment requests you create for your sub-merchants unlocks two capabilities." Commission can be taken
two ways:

- a **platform-wide percentage** configured under Settings → Platform ("Commission Rate"), or
- a **per-transaction fixed fee** via `platform_commission_amount`, which "takes precedence over the
  percentage Commission Rate for that transaction." Constraints: "Must be greater than `0`, have at
  most two decimal places, and be **less than** `amount`."

Settlement: "the sub-merchant receives the payment amount minus HitPay's processing fee and the
commission."

**How this compares to xenPlatform — and the important caveat.** Mechanically this is a genuine
split-payment capability, and the per-transaction `platform_commission_amount` maps well onto this
project's model where platform profit is computed server-side per order (ORD-9 / `PricingService`).
**But the docs do not state whether sub-merchants get their own HitPay dashboard or complete their own
KYC** — and that is precisely the axis ADR-001's OWNED-vs-MANAGED decision turned on. The founder's
recorded, non-negotiable requirement is that resellers must never know who the underlying processor
is. HitPay Platforms leans structurally toward the MANAGED shape (funds settle *to the sub-merchant*,
implying the sub-merchant is a real onboarded HitPay account), which would **break** that
requirement. **This is UNVERIFIED and is the single most important question to ask HitPay sales
before choosing HitPay as the Phase-2 split-payment gateway.**

### 2.7 Idempotency key on payment creation

**No.** No idempotency key field or header is documented on `POST /v1/payment-requests`. Same posture
as Xendit and CHIP — `reference_number` uniqueness enforced by us is the dedupe mechanism.

**All three gateways agree on this**, which is itself a useful conclusion: the existing
reference-uniqueness approach is not a Xendit workaround to be designed away, it is the industry norm
here, and it carries forward unchanged to either candidate.

---

## 3. Comparison table

| Dimension | Xendit (ADR-001) | CHIP | HitPay |
| --- | --- | --- | --- |
| MY licence | Operating in MY | **BNM Non-Bank Merchant Acquirer** (trade press) | **BNM Merchant Acquirer** (own docs) |
| FPX | **RM2.10 flat** (personal) / RM2.90 (business) | **RM1 flat** (B2C) / RM2 (B2B1) | yes — **rate not published** |
| DuitNow QR | RM2.90 flat (online banking; QR variant unconfirmed) | 1.00% (min RM0.15) | yes — rate not published |
| E-wallets | GrabPay 2.00% / TnG 1.80% / ShopeePay 2.50% / WeChat 2.50% (all +RM0.90) | 1.40% all (TnG/GrabPay/ShopeePay/Maybank QR) | TnG 1.9% / GrabPay 2% / ShopeePay 2.2% (recurring card shown) |
| Cards, domestic | credit 2.00% / debit 1.90% (+RM0.90) | credit 2.0% / debit 1.0% | 1.2% + RM1 |
| Cards, foreign | 3.80% + RM0.90 | 3.0% | 3% + RM1 (+2% FX) |
| Boost | — | **UNVERIFIED** (not in enum) | **UNVERIFIED** (not in docs list) |
| Channel breadth | moderate | narrower | **widest** (DuitNow OB, MAE, Alipay, WeChat, Borderless QR) |
| Auth | Basic (secret key) | Bearer key (Collect); +HMAC-SHA512 (Send) | `X-BUSINESS-API-KEY` |
| Sandbox | real, FPX proven live | test-mode **keys only**, same host | real, **separate host + dashboard**; **FPX not listed as testable** |
| Webhook verify | plain token compare | **RSA PKCS#1 v1.5 / SHA256, `X-Signature`** + public-key fetch | **HMAC-SHA256, `Hitpay-Signature`** + per-webhook salt |
| Webhook events | invoice/payment callbacks | 25+ typed events | typed events + object headers |
| Split / sub-merchant | **xenPlatform** (OWNED / MANAGED) | **none published**; CHIP Send = flat payout API | **Platforms / `X-PLATFORM-KEY`** + `platform_commission_amount` |
| Reseller can see the processor? | OWNED = no (requirement met) | payouts are ours to push → **no** | **UNVERIFIED — likely yes, i.e. risk** |
| Idempotency key on create | **no** | **no** | **no** |
| Setup / monthly fee | — | none | none (but **+0.2%** software-tools surcharge) |

---

## 4. Open questions / gaps to close before the ADR is Accepted

Ordered by how much they could change the decision.

1. **HitPay's actual FPX and DuitNow rates.** Not published anywhere HitPay controls. Without these,
   HitPay cannot be fee-compared to CHIP at all — and fees are the entire reason for this research.
   Must come from HitPay sales or the pricing calculator. The 1.8% + RM0.40 / 1.2% figures floating
   in search results are **not** primary-source-verified and must not be used.
2. **This project's real average order value — narrower scope after §0.1's correction.** No longer
   needed for FPX (now a flat-vs-flat comparison, CHIP wins unconditionally — see corrected §1.3), but
   still decides the e-wallet/card comparison: e.g. CHIP's flat 1.40% e-wallet rate vs Xendit's
   per-channel 1.80%–2.50% crosses over at different basket sizes per channel. Computable from our own
   order data today.
3. **Whether HitPay Platforms sub-merchants get their own dashboard / KYC.** Determines whether
   HitPay can satisfy the founder's absolute "reseller never sees the processor" requirement
   (ADR-001, 2026-07-24 addendum), or whether it is structurally MANAGED-shaped and therefore
   disqualified for Phase 2 split payments.
4. **Whether HitPay's sandbox can test FPX / DuitNow QR at all, on a Malaysian business account.**
   Documented sandbox methods omit both; ADR-001's Xendit integration was de-risked precisely by
   live sandbox FPX calls. If HitPay can't offer that, integration risk rises sharply.
5. **Whether the +0.2% HitPay software-tools surcharge applies to a pure API/checkout integration.**
6. **Boost support at either gateway** — absent from both primary sources despite third-party claims.
7. **Whether CHIP has an unpublished marketplace/platform programme.** Absence from docs ≠ absence
   from product.
8. **CHIP's BNM Non-Bank Merchant Acquirer status against BNM's own register** — currently sourced to
   trade press, not a regulator page or CHIP's own docs.
9. **CHIP's RSA public-key rotation behaviour** — docs give the retrieval endpoints
   (`GET /public_key/`, `Webhook.public_key`) but not a rotation policy, which the adapter's caching
   strategy depends on.
10. ~~**Xendit's real current MY rates**, written down.~~ **Closed, same session — see §0.1.**
    Fetched live from `xendit.co/en/pricing`; also used to fix `PaymentMethodSeeder`'s stale defaults
    (`docs/prd.md` §14, 2026-07-30). Direct consequence: item #2 above (CHIP's FPX breakeven) is now
    answerable and turned out to need no breakeven calculation at all — see the corrected §1.3.

## 5. Preliminary read (not a recommendation — pending §4)

The two candidates fail and succeed on *opposite* axes, which suggests the concurrent-two-gateway
option the `payment_methods.gateway` column already enables may beat a wholesale swap:

- **CHIP** is the only one with a real published rate card, is Malaysia-native, and its flat-RM1 FPX is
  a confirmed, basket-size-independent win over Xendit's own flat RM2.10/RM2.90 (§0.1, §1.3) — not just
  "genuinely differentiated," an actual per-transaction saving on what's very likely this storefront's
  dominant channel. But it has the most expensive webhook integration (RSA), the weakest test-mode
  isolation (key-switched, same host), narrower channels, and no split-payment story for Phase 2.
- **HitPay** has the cheapest webhook integration (HMAC-SHA256), the best sandbox, the widest channel
  list, and the only real xenPlatform analogue — but publishes no rate for the channel that matters
  most, may not be sandbox-testable on FPX, and its sub-merchant model may structurally violate the
  founder's reseller-invisibility requirement.

Either one costs exactly what ADR-001's 2026-07-25 addendum predicted: one `PaymentGateway`
implementation, one `payment-gateway.<name>` container binding, one new webhook controller + route,
and admin flipping `payment_methods.gateway` values. Neither requires touching `CheckoutService`,
`CheckoutController`, the Order model, or the ledger. The seam holds for both candidates — that
prediction is confirmed, not assumed.
