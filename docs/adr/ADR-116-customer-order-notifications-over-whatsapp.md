# ADR-116: Customer order notifications over WhatsApp (OpenWA), not email — closes audit M-11

> **Standing (2026-11-14):** In force; every WhatsApp send goes through one outbox, idempotently, with replies on their own lane and stuck or uncertain messages alerted (2026-11-14 addendum); a privacy-id chat that matches the checkout number through OpenWA's lookup is tied to it (2026-11-03 addendum); decision 8's phone normalisation extended for a Malaysian mobile typed without its leading 0 (2026-10-30 addendum); the WhatsApp chat (an `@lid` too) is the identity and a chat that sends an order number follows that order (2026-11-01 addendum); send queue generalised to (session, chat) by [ADR-125](./ADR-125-login-top-up-manual-supplier.md); a joki progress message is queued with an earliest send time and worded at send time ([ADR-126](./ADR-126-joki-mlbb.md), 2026-10-23). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted — grilled (`/mattpocock-skills:grilling`) with the founder 2026-09-30, five rounds. **PR-B1 (#323) and PR-B2 (#324) both built 2026-09-30** and released `staging`→`main` the same day (#325). The switch is ON and was live-tested by the founder. Read the two addenda at the end of this entry before building on it:
- The **build addendum's items 1–2 are superseded** by the **post-live-test addendum**: the order number is the opt-in, and there is one timeline status card.
- That follow-up is **#326**, released to `main` 2026-10-01 via #332.
- The **2026-10-12 addendum** removes the repeat throttles (post-live-test addendum decision 3 and its 10-minute not-found rule).

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
7. **Anti-ban is mostly OpenWA's, not ours.** The prod box ran OpenWA v0.23.7 when this was decided (the version today: PRD §10, OpenWA row), which already ships:
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

### 2026-10-12 addendum — every order-number message is answered; the repeat throttles are removed (PRD §16 item 69)

**Context.** The post-live-test addendum added two repeat throttles: no repeat of
the same status card to a number within 30 minutes (decision 3), and one "not
found" reply per number every 10 minutes (decision 1). Both were a
`Cache::add` that returned before any `customer_notifications` row was written.
Read-only prod check, 2026-10-12:
- On 2026-10-05 the founder's test number sent the order number three times
  within four minutes and got one card. Two messages 26 seconds apart and one
  later got silence, with no row for admin to see.
- The throttle was set before the row was written. If the write failed once and
  OpenWA retried the webhook, the retry hit the throttle: silence, no trace.
  Read from code; not reproduced in prod.
- The inbound message id is already the `dedupe_key`, so a retried webhook
  never double-sends. The throttle was a second, weaker mechanism.
- Prod scale: 11 `customer_notifications` rows, 2 numbers, all founder tests.
  No real customer has used the channel yet.

**Decision.**
1. Every message carrying an order number gets the status card, even when
   nothing changed. This replaces decision 3 of the post-live-test addendum.
2. A mistyped order number gets the "not found" reply every time. This replaces
   the 10-minute rule in decision 1 of that addendum.
3. No per-number cap, by the founder's call. Evidence: the founder's chatbot
   project (`/Project/chatbot`, an OpenWA-based KeroxShop support bot, snapshot
   to 2026-09-14) answers every `ORD-` message without a limit. Over six months
   it saw 4,137 direct messages from 448 chats, 144 of them with an order
   number, and the busiest 10-minute window for one chat held 3. Limits of that
   evidence: it is a snapshot, not live prod, and a different business with
   more traffic.
4. Retry safety stays on the `dedupe_key` (inbound message id). A new test
   delivers the same message twice and expects one row.
5. Unchanged: STOP is never undone by an order-number message, a number that
   sent STOP still gets a card when it writes first, receipts and vouchers are
   not affected.

**Rationale.** Silence on a direct question is worse than a repeated card, and
the throttle's only real job (protecting the one paced lane from a flood) is not
a risk at this volume. Removing it also closes the retry hole.

**Consequence to track.**
- **No tripwire.** Horizon's `LongWait` (600 s on `redis:whatsapp`) cannot see a
  flood: sends are dispatched with a delay and a delayed job does not count as
  waiting. **Revisit when** a voucher or receipt arrives much later than usual,
  or admin sees many identical `status_card` rows for one number. Then add
  either a DB count of recent replies per number or a guard that skips a reply
  when `whatsapp:next-send-slot` is too far ahead. Either can be added without
  changing stored data.
- Considered and not built: the chatbot's 10-second per-chat debounce (would
  merge rapid duplicates, but is in-process state and adds a fixed delay),
  escalation to an admin group, a per-chat AI/admin/paused mode, and image OCR
  for order numbers. They belong to the CS-agent ADR (PRD §16 item 82).
- The admin order page already shows every row as a card, so repeated cards
  will appear there. Grouping them is not built; do it if the page gets noisy.

### 2026-10-30 addendum — a Malaysian mobile typed without its leading 0 (revises decision 8)

**Found in production.** Order PG-L4RCBELJ9RE3 (OhahaStore): the customer asked for help with the Contact Support button, got the status card, and after an admin Resend the order became Delivered, but **no receipt was queued and no `customer_notifications` row exists**. The order's `customer_phone` was typed as ten digits starting with `1` (no `0`, no `+60`); WhatsApp wrote from the twelve-digit `60…` number. Decision 8's normaliser took "anything else" to already carry its country code, so the order's number stayed `1…` and the sender's was `60 1…`. The opt-in was recorded under the second, `receivesReceipts()` looked up the first, found no opt-in, and `orderDelivered()` returned without a row (by design: not sending is the normal case).

**Scope found (read-only, 2026-10-30).** 1 of 42 real storefront orders had a number that did not normalise to a `60…` number: this one. The same normaliser feeds the joki progress updates, the voucher messages and the voucher redemption phone match, so a customer typed like this would also have had a voucher sent to a number that is not theirs.

**Decision:** `PhoneNumber::normalize()` treats a 9 or 10 digit number starting with `1` as a Malaysian mobile missing its trunk `0` and makes it `60…`. Country code 1 numbers always have 11 digits, so they are never caught by the rule. `1123456267`, `01123456267`, `601123456267` and `+60 11-2345 6267` are now one number. The stored `customer_phone` is still never rewritten (suppliers receive it as typed).

**Not done:** warning the customer at checkout, and any change for numbers that start with other digits (landlines and other countries stay as before). The fix does not run backwards: PG-L4RCBELJ9RE3 did not get its receipt (the founder was advised to tell the customer by hand).


### 2026-11-01 addendum — the WhatsApp chat is the identity; a chat that asks about an order follows it (revises decisions 5 and 9, build addendum 2)

**Status:** Accepted — grilled with the founder 2026-11-01 (two rounds) and stress-tested against code and production the same day.

**Found in production.** Order PG-WSEZAO6R4TGU (OhahaStore, DuitNow, paid, failed at the supplier, delivered after an admin Resend). The customer sent the order number to the CS number and got no status card, and after the Resend no receipt. The sender arrived as a WhatsApp privacy id (`234…@lid`) and OpenWA could not resolve a phone (`RESOLVE_LID_TO_PHONE` is on; WhatsApp does not reveal the number of a stranger). Build addendum 2 ignored a message with no usable phone, silently (an `info` log, below prod's `warning` level), so the checkout phone never opted in and `orderDelivered()` wrote no row. Scale (read-only, OpenWA DB): 4 distinct `@lid` senders since 2026-09-25, 2 of them on 2026-11-01, 1 message with an order number. The same gap already exists for a customer who writes from a number other than the checkout one: their opt-in lands on that number, and the receipt looks up the checkout number.

**What WhatsApp allows (checked in OpenWA v0.25.0 and Baileys 7.0.0-rc14 on the box, and upstream v0.27.0).** `@lid` → phone: only when the gateway already holds the mapping. Phone → `@lid`: Baileys can ask WhatsApp (`lidMapping.getLIDForPN`), but OpenWA calls it only inside a send and exposes no endpoint. Sending to an `@lid` chat works (11 staff replies to `@lid` chats in the OpenWA DB). OpenWA types an `@lid` chat as `kind: individual`. The CS API key has no chat restriction.

**Decisions.**
1. **Inbound identity is the sender's chat.** A direct message on the CS session is handled when it carries a usable phone (`@c.us`, or OpenWA's `senderPhone`) **or** an `@lid` chat id. Replies (status card, not-found, STOP/START) go to that chat. Nothing is ignored for lack of a phone.
2. **A chat that sends an order number follows that order.** The followers of an order are the chats its `status_card` rows (CS session) were sent to. The row already records "we answered this chat about this order", so there is no new table. Consequence: a future pruning of `customer_notifications` must keep `status_card` rows of undelivered orders, or followers are lost (pinned by a test).
3. **One receipt per recipient, chosen in one place.** For the Delivered receipt and joki progress:
   - if the checkout phone has **ever** contacted us (a contact row, opted in or out), it alone decides: it gets the message when opted in, and nobody does when it said STOP;
   - otherwise every follower chat that has not said STOP gets it. There is no cap on followers (founder: order numbers are random; revisit if an order gathers many).
   This replaces "one receipt per order" (decision 4's unique index) with one per recipient: a follower's dedupe key carries its chat id, so a retried webhook or event still never double-sends. The receipt holds what the status card holds, and the status card already goes to whoever sends the order number (post-live-test addendum), so no new data is exposed.
4. **Vouchers are unchanged:** still to the checkout phone only, cold. A voucher code is money and must not reach someone who merely knows the order number.
5. **A contact is a phone, an `@lid` chat, or both.** `whatsapp_contacts.phone` becomes nullable and gains a unique nullable `chat_id`. STOP/START work for an `@lid` chat. When a message carries both (an `@lid` chat with a resolved `senderPhone`) both are stored on the phone's row. Rows are never merged; when a phone and a chat each have a row, **STOP wins**: either one opted out means no receipt.
6. **Admin order page:** a row sent to an `@lid` chat shows "WhatsApp chat (number hidden)" with the id in small text.
7. **No local patch to OpenWA.** A phone → `@lid` lookup endpoint is asked for upstream (draft prepared for the founder to file). When it exists, a follower `@lid` can be checked against the checkout phone and that phone opted in for later orders; until then the follower rule covers the order the customer asked about.

**Not done.** No backfill: PG-WSEZAO6R4TGU's customer was answered by hand (founder). No email fallback. A customer who writes only as `@lid` and never sends an order number still gets nothing automatic (there is no order to follow).

**Consequence to track.** WhatsApp usernames (phone optional) are rolling out; Baileys does not expose them yet (OpenWA issue #642). The chat-as-identity rule is what keeps working when the phone disappears; revisit when Baileys ships usernames.

### 2026-11-03 addendum — a privacy-id chat that proves it is the checkout number is tied to it (builds 2026-11-01 decision 7; PRD §16 item 156)

**Status:** Accepted — the founder's design (2026-11-02: "the panel's checkout phone is the source of truth; check the `@lid` against it and tie them for good"), five questions answered by the founder 2026-11-03 ("setuju semua"), then a code-trace stress test.

**Context.** OpenWA v0.27.1 (live since 2026-11-02) answers `GET /api/sessions/{id}/contacts/{number}/lid` without sending (rmyndharis/OpenWA#1969); the CS key is `operator` with no chat restriction and was checked on prod. Until now an `@lid` chat that sent an order number only followed that one order; the next order from the same customer got no receipt unless they sent its number again. Production on 2026-11-03: 5 `whatsapp_contacts`, 2 chat-only, 1 STOP, none with both.

**Decisions.**
1. **The checkout phone is the truth.** When a direct message on the CS session comes from an `@lid` chat with no phone, carries an order number, and that order is in scope (storefront, not test) with a usable checkout phone, a queued job asks OpenWA for the checkout phone's `@lid`. Only an exact match ties them. No match, `lid: null`, or no checkout phone: nothing changes, and the chat stays a follower of that order (2026-11-01 decision 2).
2. **Tied = one `whatsapp_contacts` row with both `phone` and `chat_id`.** No new column: `chat_id` exists since the 2026-11-01 addendum. The checkout phone is then "known", so `receiptRecipients()` sends every later order's receipt and joki progress to it (OpenWA delivers a phone send into the customer's `@lid` chat).
3. **Two rows for one person are merged (revises 2026-11-01 decision 5's "rows are never merged" for a verified pair only).** In one transaction with the rows locked: the phone row is kept; it takes the chat id; `opted_out_at` is set if either row said STOP (STOP wins); `opted_in_at` is the earlier one; the chat row is deleted first (both columns are unique). A new row's `opt_in_source` is `lid_match`.
4. **If the phone is already tied to a different `@lid`,** the newest lookup wins (a number that changed hands); STOP state is kept.
5. **Only an order number can tie.** A chat that writes without one has nothing to compare against and stays as today. No manual "tie" button in the admin (not built now).
6. **Vouchers unchanged:** still to the checkout phone only. A tied chat is the same account, so it receives them there.
7. **Lookup failure** (OpenWA down, `503`, timeout): the job retries (3 failures, at most an hour), then gives up silently; the next order-number message tries again. The webhook never waits on the lookup.
7a. **Built for a burst** (founder's question, 2026-11-03: an event where many customers send order numbers at once). The job runs on its own `whatsapp-lookup` queue, which the one `supervisor-default` worker serves only after `default` (where reseller bot replies run), so a burst never delays a reseller; one job per chat for 10 minutes (`ShouldBeUnique`); at most 20 lookups a minute (a `whatsapp-lookup` rate limiter), the rest waiting their turn, so WhatsApp never sees a flood of contact queries. No new Horizon process.
8. **Admin:** a notification row sent to an `@lid` chat that is now tied shows "WhatsApp chat · verified as <phone>" instead of "number hidden".

**Known ceiling.** A joki order tied mid-play switches its recipient key from the chat to the phone, so the progress interval can restart once (at most one extra message).

### 2026-11-14 addendum — one outbox for every WhatsApp send; idempotent sends; replies on their own lane (revises decisions 7 and 9; ADR-125 decisions 53-54)

**Status:** Accepted — grilled with the founder 2026-11-14 (three rounds) and stress-tested against the code by one fork per layer (outbound, inbound, monitoring). Bot-side decisions are in [ADR-075](./ADR-075-reseller-bot-channel.md)'s addendum of the same date.

**Context (verified on prod 2026-11-13).**
- Duplicate messages: 18 bot and 3 customer-support outgoing duplicates in OpenWA's `messages` (same chat and text within 2 minutes), 5 of them on 2026-11-13. Cause: `send-text` sometimes takes over 10 s (typing simulation up to 5 s plus the Baileys group send); our client times out and the job retries, while OpenWA still sends the first request.
- Two send paths: `WhatsAppOutbox` (persisted, deduplicated, paced) and `OpenWaClient::sendText()` → `SendResellerBotReplyJob` (12 call sites, queue-only, 3 tries over about 25 s, then the reply is lost).
- `SendCustomerWhatsAppJob` fails a row after `maxExceptions` (3) with 60/300 s backoff: about 6 minutes, not the 2 days `retryUntil` suggests. Only a 429 pacing refusal waits longer.
- `config/queue.php` has `after_commit => false`; `enqueue()` dispatches inside its caller's transaction.
- OpenWA v0.29.1 already supports `Idempotency-Key` on its send routes (24 h window; replay, `IDEMPOTENCY_KEY_IN_PROGRESS`, `IDEMPOTENCY_OUTCOME_UNKNOWN`, `IDEMPOTENCY_KEY_REUSED`).

**Decision.**
1. **One send path.** Every outbound WhatsApp message is a `customer_notifications` row sent by `SendCustomerWhatsAppJob`. `OpenWaClient::sendText()` and `SendResellerBotReplyJob` are retired (the job class stays one release so queued jobs drain). An architecture test allows `sendNow()` only from the outbox job. The table name stays; it is the WhatsApp outbox.
2. **Dispatch after commit.** `WhatsAppOutbox::enqueue()` dispatches with `afterCommit()`, so a job never runs before its row exists.
3. **Frozen text.** The first attempt renders the text (`prepare()` for customer, supplier and joki rows) and stores it on the row before sending; a retry never re-renders. Only a revived skipped row renders again (its `attempts` reset to 0). The one exception is a supplier post holding the login-link placeholder: ADR-125 decision 52 keeps the link out of storage, so it is minted on each attempt and the row keeps its template. Its key comes from the template, so a retry after a timeout that did send meets `IDEMPOTENCY_KEY_REUSED` and ends `unknown`, never sent twice.
4. **Idempotent sends.** Every send carries `Idempotency-Key: cn-{row id}-{first 16 hex of sha256(chat id + text)}`. Outcomes, branched on the response `code`, not the HTTP status:
   - `201` (fresh or `Idempotent-Replayed`) → `sent`;
   - `409 IDEMPOTENCY_KEY_IN_PROGRESS` → released, tried again with the same key;
   - `409 IDEMPOTENCY_OUTCOME_UNKNOWN` → status `unknown`, never re-sent automatically;
   - `422 IDEMPOTENCY_KEY_REUSED` → `unknown` too: an earlier attempt with this key reached OpenWA with other text, so the message may be in the chat.
5. **Retry until the gateway is back.** An unreachable gateway, a timeout, a 5xx or a not-ready session is released with a growing delay (like a 429), bounded by the row's lifetime (decision 7). Only definite refusals count toward `maxExceptions`. This is safe only because of decision 4.
6. **Two lanes.** Replies to someone's command (a reseller's or a supplier's) go out on a new one-worker `whatsapp-reply` lane with no 10-30 s gap. Messages we start (customer receipts, supplier-group posts, rebate notices) keep the `whatsapp` lane and the gap. The class is set from the row's `event`, not by the caller.
7. **Lifetime by event class.** A read reply (balance, price list, errors, rate-limit notices) expires 3 minutes after the row was created (status `expired`). A money message (order received, delivered, failed, refund, wallet top-up) and every customer/supplier message is tried for 2 days, then `failed`.
8. **Latest order status only.** Before sending an order's "received" message, the job checks for a newer lifecycle row for the same order in any status; if one exists the row is `superseded`. "Failed" and "refund" both send. A lifecycle message sent more than 3 minutes after its event says so, with the event's time.
   A session with no id or key (tests, local dev) skips the row (`skipped`, "not configured"), as the old bot reply path did.
9. **`message.failed`** is subscribed on the `customer-support` webhook. It marks the row whose `wa_message_id` matches as `failed`. Group acks are not used (a Baileys group message reaches `delivered` only when every member has it).
10. **Alerts.** A scheduled CRITICAL task emails every admin through `BackupFailureAlerter` (area "WhatsApp") when a row has waited over 5 minutes past its due time, or when a row turned `unknown` or `failed`. Each kind is throttled to one email per 30 minutes (`Cache::add`), with no recovery email, as the existing queue-wait and supplier-balance alerts do. A dead scheduler fails `/api/health` through the stale-task rule. Session down and session restriction are caught by the existing UptimeRobot check on `/api/health/whatsapp` (decision 11).
11. **Restriction in the health check.** `OpenWaClient::sessionStatus()` also returns the session's `restriction` from `GET /api/sessions/{id}`; `/api/health/whatsapp` answers 503 when either session is restricted. Its body says `restricted`, never the kind or code. Polled live, so no cached state can get stuck; no `session.restriction` webhook is subscribed.
12. **Middleware → WhatsApp** lists outbox rows (time, session, masked recipient, event, status, error) read-only, filterable by status. No resend button in this phase.

**Rationale.** One path means one retry policy, one dedupe and one place to look. The idempotency key moves "did it already go out?" to the one party that knows. Retrying until the gateway is back turns an outage into a delay instead of a lost message, and the lifetime cap keeps a stale balance reply from arriving an hour late.

**Consequence to track.**
- OpenWA ops, done by the founder: add `message.failed` to the `customer-support` webhook's events; `SIMULATE_TYPING_MAX_MS=1500` in OpenWA's `.env` (typing simulation is global, so this shortens it for both sessions; customer messages keep the 10-30 s gap).
- Remove `SendResellerBotReplyJob` with the OpenWA v0.30.0 upgrade PR.
- Sending every Delivered receipt without opt-in revisits decision 5 and the cold-reachout rationale; it needs its own addendum with opt-in and volume data, not this change.
