# ADR-116: Customer order notifications over WhatsApp (OpenWA), not email — closes audit M-11

> **Standing (2026-10-09):** In force. Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — grilled (`/mattpocock-skills:grilling`) with the founder 2026-09-30, five rounds. **PR-B1 (#323) and PR-B2 (#324) both built 2026-09-30** and released `staging`→`main` the same day (#325). The switch is ON and was live-tested by the founder. Read the two addenda at the end of this entry before building on it:
- The **build addendum's items 1–2 are superseded** by the **post-live-test addendum**: the order number is the opt-in, and there is one timeline status card.
- That follow-up is **#326**, released to `main` 2026-10-01 via #332.

**Context:** the 2026-09-28 audit (M-11) found no customer order notification of any kind. PRD §7.1 step 11 (notify on delivery, invite a review) and §7.5 step 4 (the customer receives their voucher code) were specified but never built. The sharp edge is §7.5: a customer whose order fails gets a store-credit voucher (ADR-004), but the code is never sent anywhere and the track-order page doesn't show it, so the refund only reaches the customer if an admin contacts them by hand.

The founder chose WhatsApp over email, because customers rarely read email. Every storefront checkout already requires a phone number (`customer_phone`, required since 2026-07-30). OpenWA (ADR-075/114) already runs on the prod box with two sessions: `reseller-bot` (+65 8275 1992) and `customer-support` (+60 11-4301 3150, a general number). The Laravel backend's OpenWA key is scoped to `reseller-bot` only.

**Decisions:**

1. **Channel: WhatsApp only, sent from the `customer-support` session.** No email, and Plunk stays for OTP, receipts and ops alerts. **The voucher code is never shown on the track-order page**: the founder ruled it out on privacy grounds, even though redemption also needs the full email or phone. When a WhatsApp send fails, the admin handles it manually from the order's notification status (decision 9).
2. **Scope: storefront orders only**, including affiliate brands. Reseller wallet orders (they refund to the wallet, and the bot/portal already informs them) and `is_test` orders are excluded.
3. **Per-brand message text from one general number.** Every message opens with the order's brand `store_name` and links to that brand's storefront (its verified primary `affiliate_domains` hostname, else the default storefront URL). The number itself stays the platform's general CS number, so its WhatsApp profile name must stay brand-neutral.
4. **Events:**
   - **Proactive (cold allowed):** a voucher issued from a failed order; a voucher restored (restore-only, ADR-024 addendum); a standalone Path A voucher, but only when the admin fills in the new optional phone field.
   - **Opt-in only:** Delivered receipt with a review link.
   - **The receipt fires on the transition into Delivered, whatever the path:** a first delivery, an admin Resend/Retry after a failure, a Pending order confirmed by poll or webhook, or an admin Mark Delivered from NeedsReview. All four already converge on `OrderFulfillmentService::creditProfit()`, which is the single trigger point. A receipt and a voucher can never both go out for one order, because a compensated order blocks resend (`isAlreadyCompensated()`). The unique index in decision 9 caps it at one receipt per order. A number that opts in only after the order was Delivered gets the receipt through the opt-in auto-reply.
   - Payment-received and failed/needs-review messages are not sent: the status page covers them live, and the outcome ends up as either Delivered or a voucher anyway.
5. **Opt-in is per phone number, not per order, and it persists across orders and brands.** Two ways in:
   - A new **"Get updates on WhatsApp"** button on the order status page, with prefilled text the backend recognises. It opts the number in and replies at once: the receipt if the order is already Delivered, otherwise "we'll message you when it's complete".
   - The existing **Contact Support** button already prefills the `PG-…` order number. Any message on the CS session containing one opts the number in **silently**, with no auto-reply, so staff can take the conversation.

   **Opt-out:** replying `STOP` stops receipts. Voucher messages still go out, since that is the customer's money. The first receipt carries a "Reply STOP to stop receipts" line. The reason is anti-ban: a customer who feels spammed taps Report/Block, and reports are what get numbers banned.
6. **Both order-page WhatsApp buttons (support and opt-in) point to the platform CS number** (`OPENWA_CS_PHONE`). A brand's panel-configured `support_phone` is used only in the footer and on the price-list page. Consequence: order issues from affiliate-brand customers reach platform CS staff, who reply as that brand.
7. **Anti-ban is mostly OpenWA's, not ours.** The prod box runs OpenWA v0.23.7, which already ships:
   - `SIMULATE_TYPING` (on by default: a length-scaled, jittered typing pause, max 5s);
   - `SEND_PACING` (off by default; the founder turns it on with the default warm-up `20,40,80,160,320,640,1000`/day, `SEND_PACING_COLD_DAILY_CAP` `5,10,20,40,60,80,100`, and a failure breaker of 5 failures → 15 min pause).

   OpenWA counts a message as a cold reachout only when there is no chat history in either direction, and answers to a customer who wrote first are never capped. Over the limit, it answers HTTP 429 `SEND_PACING_LIMITED` with `retryAfterSeconds`. On our side: a dedicated `whatsapp` queue lane with **one** worker, a random 10–30s gap between recipients, and on 429 the job is released for `retryAfterSeconds` (never dropped). Any other failure gets 3 tries, then status `failed`. Every number is a config knob: WhatsApp's real thresholds are unpublished, and this starts from WAHA's and baileys-antiban's published guidance.
8. **Phone numbers are normalised only at send time.** Strip non-digits, turn a leading `0` into `60`, keep an existing country code, and log-and-skip anything implausible. The stored `customer_phone` is never rewritten, because the same field goes to suppliers (Gamevion's `telp` format has bitten before). The same normalisation fixes a related bug: voucher phone ownership (`VoucherService::assertUsable()`) now compares normalised digits instead of the raw string, so `012…` at checkout matches `+6012…` at redemption.
9. **`customer_notifications` table:** one row per outbound message (`order_id`/`voucher_id`, event, normalised phone, status `queued|sent|failed|skipped`, attempts, `sent_at`, error). A unique index on (event, reference) makes a job retry or a repeated event never double-send. The admin order page shows the WhatsApp status, so the admin knows when to contact the customer by hand. Opt-in and opt-out live in a separate per-phone table (normalised phone, opted_in_at + source, opted_out_at).
10. **Master switch** `PlatformSettings.whatsapp_notifications_enabled`, **default OFF**. The founder turns it on to test, and off if anything misbehaves. There are no per-event toggles (SET-8 stays unbuilt, YAGNI).
11. **OpenWA wiring:**
    - New env `OPENWA_CS_SESSION_ID` and `OPENWA_CS_PHONE`, plus an API key with access to the CS session.
    - `OpenWaWebhookController` filters on the envelope's `sessionId`: `reseller-bot` traffic goes to `ResellerBotService` exactly as today, and CS traffic only ever reaches the opt-in handler, never the bot.
    - `OpenWaSessionStatus` is tracked per session (it is one global cache key today, so a second session's status would overwrite the bot's). The admin dashboard shows both.
12. **Language:** messages are in English for now. The customer's prefilled button text may stay BM, like the existing support text.
13. **Build split:**
    - **PR-B1** (closes M-11's core): voucher notifications, `customer_notifications`, the switch, the `whatsapp` lane with pacing and 429 handling, the webhook `sessionId` filter and per-session status, phone normalisation plus the voucher-match fix, and the Path A phone field.
    - **PR-B2:** the opt-in table and detection, the storefront button, both order-page buttons moved to `OPENWA_CS_PHONE`, the auto-reply, and Delivered receipts with STOP handling.

**Rationale:** WhatsApp reaches customers who ignore email. OpenWA and a general CS number already exist. The real ban risk is cold reachouts, not speed (WAHA: *"never initiate a conversation"*). So only the rare, high-value voucher message goes out cold, and the high-volume receipt waits for the customer to write first. Leaning on OpenWA's own pacing means the refusal, cap and breaker logic is tested upstream instead of reinvented here.

**Consequences to track:**
- **OpenWA is unofficial WhatsApp Web automation, not the Business API.** A ban on the CS number takes the human support channel down with it. If volume grows, or a restriction (error 463) ever appears, revisit a move to the official WhatsApp Business Cloud API as its own ADR.
- **The founder must do three things before the switch goes ON:** turn on `SEND_PACING` in OpenWA's `.env` and restart it; create a CS-session webhook to the backend endpoint (needed for PR-B2); issue an API key with CS-session access.
- There is no email fallback. If WhatsApp delivery proves unreliable in the `customer_notifications` data, adding email is the first lever.
- A per-affiliate WhatsApp session (a brand's own number) is out of scope and would be a future ADR, triggered when a real affiliate asks.

### Build addendum (2026-09-30) — decisions settled while building PR-B1/PR-B2

1. **The receipt goes only to the order's own phone number.** The grill left
   open what happens when the "Get updates" message comes from a number other
   than the one on the order. Knowing an order number must not redirect
   someone else's receipt, which carries the Player ID and amount. So a
   mismatched number gets a reply asking them to message from the checkout
   number, and the order's phone is never changed. Opt-in stays per phone
   (decision 5), so this is consistent with it.
2. **Opt-in and STOP detection.** A direct message (`kind=individual`, not
   `fromMe`) on the CS session:
   - that is exactly `STOP` (any case) → opt-out;
   - that contains `PG-…` plus the word "update" → the updates button:
     opt in, reply, and clear an earlier STOP;
   - that contains `PG-…` alone → the support button: silent opt-in, never
     clearing a STOP.

   The sender comes from `from` (`@c.us`), or OpenWA's `senderPhone` for a
   WhatsApp privacy id (`@lid`). No usable phone means the message is ignored.
3. **Every receipt carries "Reply STOP to stop receipts",** not just the
   first. It's simpler, and it never leaves a customer without the way out.
4. **The "Get updates" button is hidden on a failed order.** That customer's
   next message is the voucher, which goes out proactively anyway.
5. **Receipt trigger:** `OrderFulfillmentService::creditProfit()` schedules
   `CustomerNotificationService::orderDelivered()` in `DB::afterCommit`. The
   send is never tied to a delivery that rolled back. It is wrapped in
   try/catch, so a notification problem can never fail or retry a delivery.
6. **Pacing mechanics:**
   - Each queued message reserves a send slot 10–30s after the previous one
     (a Redis value under a lock) and is dispatched with that delay.
   - A 429 `SEND_PACING_LIMITED` from OpenWA releases the job for
     `retryAfterSeconds`. Because a release also counts as a Laravel attempt,
     the job uses `retryUntil()` (2 days) plus `$maxExceptions = 3` instead of
     `$tries`.
7. **Webhook ordering at release:** the CS session's OpenWA webhook must be
   created only after this code is live. The code before this release has no
   `sessionId` routing, so a CS group message would have reached the reseller
   bot.

### Addendum (2026-09-30, after the first live test) — the order number is the opt-in; one status card for every reply

**Context:** the founder's first live test surfaced two problems.
- Opt-in detection rested on the word "update", which Malaysian customers
  write in ordinary support chats all the time ("tolong update order PG-…").
  The bot would have cut into a staff conversation.
- A message `skipped` while the switch was still off could never be sent,
  because the `dedupe_key` blocked it forever.

The founder's point: the goal is only to learn which number wrote to us
first, and the order number already proves that.

**Decisions** (they supersede build-addendum items 1 and 2):
1. **Any direct message on the CS session carrying a valid order number** opts
   the sender in and gets that order's **status card** back. It doesn't
   matter which button prefilled it or whether it was typed by hand. There is
   no keyword and no "support vs updates" split.
   - An order number we don't have (usually a typo) gets a brand-less "not
     found" reply: check for typos; the number is on the order page right
     after payment or in the CHIP payment receipt email. It goes out at most
     once per number every 10 minutes.
   - The founder's call: order numbers are random per purchase, so "not found"
     gives away nothing guessable. This reverses the first draft, which stayed
     silent.
2. **One status card layout (`OrderStatusCard`), in English:** a three-step
   timeline in the same language as the order page's own stepper
   (`✅ Paid · RM… via FPX` / `✅ Processed` or `⏳ Processing` /
   `✅ Delivered to Player ID …` or `○ Delivery…`). It carries the brand and
   order number on top, the item line, and an honest closing line per state
   (awaiting payment, processing, under review, delivered with review link,
   failed with the voucher promise). The founder's reference screenshot came
   from a competitor, so the layout was deliberately made different from its
   "emoji label: value" list (option A of three, chosen by the founder).
   - The Delivered receipt is the same card plus the STOP line.
   - The card shows only what the public track-order page shows, so it goes
     to **whoever sent the order number**. This drops the earlier
     checkout-number-only rule.
   - Future automatic receipts still go only to the order's own phone, and
     only when that phone is opted in.
3. **The same card isn't repeated to the same number within 30 minutes** unless
   the order's payment or delivery status changed, so a support chat that
   keeps quoting the order number isn't flooded.
4. **An order-number message never undoes a STOP.** Otherwise a customer who
   said STOP and then chats with support would silently get receipts again.
   `START` is the explicit way back in.
5. **A skipped row is revived once its reason no longer applies.** It goes
   back to `queued` through a conditional update, so two callers can't both
   revive it. `sent`, `queued` and `failed` rows stay final, so a message
   still never goes out twice.
6. **Order-status card UI:** the button order is Contact Support → Get Updates
   → Buy Again (the founder's call). Exactly one button is filled per state
   (decided with the impeccable skill): Get Updates while paid and waiting,
   Buy Again once delivered, and Contact Support on a failure or before
   payment. Get Updates is hidden until the order is paid, and on a failure.
   Its label is "Notify Me on WhatsApp" while waiting and "Send Receipt to
   WhatsApp" once delivered. The card heading and body follow the state.
