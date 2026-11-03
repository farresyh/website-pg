# ADR-136: Customer-support assistant on the CS WhatsApp number — answers from approved knowledge, learns only through admin approval, hands order problems to a human

> **Standing (2026-11-03):** In force. Extends [ADR-116](./ADR-116-customer-order-notifications-over-whatsapp.md) (the CS session, opt-in, status card, STOP/START) and [ADR-075](./ADR-075-reseller-bot-channel.md) (OpenWA). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Accepted (design), 2026-11-03, by the founder after four grill rounds (Q1–Q18) and a stress test against the real code (Q19–Q21 and the fixes in decision 33). Decisions 1–34 were made with the founder in Malay. The model itself is chosen at build time by decision 28's test. Not money-critical: the assistant reads order status and never writes money, orders or vouchers. PRD §16 item 158.

## Context

The founder wants the customer-support number (OpenWA session `customer-support`) to answer customers' common questions by itself, sounding natural rather than robotic. It should grow its knowledge over time ("self-learning RAG"), never handle an order problem itself, and hand a failed order to an admin.

What already exists:

- **ADR-116** auto-answers on the CS session in narrow cases: an order number gets a status card or a not-found reply, and `STOP` / `START` are handled. A message from the order page's Contact Support button opts the number in **silently, with no auto-reply**, so staff can take the conversation (ADR-116 decision 5). This ADR revisits that rule; see "Not decided yet".
- **OpenWA** stores every message in `data/openwa.sqlite` `messages` (body, direction, chatId, timestamp) and prunes rows older than `MESSAGE_RETENTION_DAYS=90` (set 2026-10-25 for PDPA). The first prune is around **2026-12-24**. OpenWA's own keyword auto-reply (`automation_rules`) is unused (0 rows).
- **Knowledge sources already in the platform:** the FAQ table (5 rows on 2026-11-03), each game's description and important notes (ADR-109), the delivery badge (instant or manual), and the voucher-only policy (ADR-004).
- **An LLM is already used**, in the admin only: the Reports Assistant (ADR-087, Gemini, curated read-only SQL views).
- **The CS number is unofficial WhatsApp (OpenWA).** A customer's Report can ban it, and with it the human support line (the reason PRD §16 item 150 waits).
- **The repo is public.** Customer messages, exports and studies never go in it.

### Production, read-only on 2026-11-03

**Messages since 2026-09-25 (`openwa.sqlite`):**

| Session | Personal chats, in / out | Group chats, in / out |
| --- | --- | --- |
| `customer-support` | 98 / 89 (about 14–18 chats) | 1,019 / 54 |
| `reseller-bot` | 64 / 56 | 171 / 152 |

The CS session's outgoing personal messages mix our automated sends (status cards, receipts, vouchers) and staff replies.

**Time from `paid_at` to `delivered_at`, 61 real delivered orders:**

| Paid → delivered | Orders | Cumulative |
| --- | --- | --- |
| ≤ 5 min | 46 | 75% |
| 5–15 min | 6 | 85% |
| 15–30 min | 4 | 92% |
| 30–60 min | 1 | |
| > 60 min | 4 | |

Every order over 30 minutes needed 1–3 admin resends. No order in the data finished by itself after 30 minutes. No paid order was stuck in Pending or Processing at the time of the check.

**What customers actually ask (CS personal chats since 2026-09-25, read masked on 2026-11-03).** About 8 real customer conversations; the rest are staff and founder tests, automated sends, and staff's outbound B2B outreach to other stores and their replies. By frequency:

| Pattern | Conversations | How staff answered |
| --- | --- | --- |
| "Didn't get 500 diamonds": the MLBB **First Topup** bonus (250+250, 50+50) only applies once per account | 4 | Explained the once-per-account rule with a screenshot of how to check in game (the + next to diamonds, the red "first recharge" mark) |
| Order failed but paid | 3 | "Kejap kita check", resend, "dah masuk" |
| Paid, not in yet / "is this an official store?" | 1–2 | Confirmed the player ID, then checked |
| Becoming a reseller / wholesale prices (in English) | 1 | Price-list link and the tier count |
| Membership OTP email not received | 1 | "Check spam" |
| Using a voucher for another package | 1 | "Boleh guna voucher untuk beli baru" |

Staff write casual Malay ("boss", "bang", "jap", "mksudnya", stretched letters, short bursts); customers use dialect and short forms ("hok", "xde", "fhm") and often send screenshots. One staff reply to a prospective reseller included internal pricing plans, the kind of text decision 2 keeps out of the knowledge.

**Order numbers carry no brand.** `OrderNumberService` makes `PG-` plus 12 random characters. The brand appears only in the Contact Support prefill text ("Salam support {storeName}, saya perlukan bantuan untuk order PG-…"), which the customer can edit.

## Decision

1. **Scope: common questions only, from knowledge.** The assistant answers what customers often ask (how to buy, prices and packages, payment, ID and server, vouchers, becoming a partner). It never resolves an order problem: no resend, no voucher, no refund promise, no status change.
2. **It learns only through admin approval.** The system finds new questions and staff answers in CS conversations and proposes them as knowledge entries. An admin approves, edits or rejects each one. Only approved entries are used for answers. Nothing a customer or staff member writes becomes knowledge on its own: one wrong or leaky staff reply would otherwise be repeated to every customer.
3. **Answers come only from approved knowledge.** When no approved entry covers the question, the assistant hands over (decision 6); it never guesses.
4. **It never discloses business-private facts.** This applies even when a knowledge entry or a customer's message contains them: cost, margin, supplier names, tier rates or `markup_percent`, other customers' data, affiliate terms, and internal operations (the repo rule: "Platform cost structure is private"). Every reply is checked against this list before it is sent.
5. **Natural tone, honest identity.** Casual Malay (or the customer's language), short messages, no robotic formatting, OpenWA's typing pause, speaking as "kami / team {brand}". It does not announce in every message that it is a bot. **When a customer asks whether it is a bot, it says so truthfully** ("Saya pembantu AI team {brand}. Kalau nak cakap terus dengan team, saya boleh panggilkan."). It never claims to be a human.
6. **Hand over to an admin when:**
   - (a) the customer gives an order number, the assistant checks it, and its delivery has **Failed**. The assistant asks for the order number first and hands over only once the order is verified as failed;
   - (b) the order is **Delivered** but the customer says it never arrived;
   - (c) a paid order is still Pending or Processing past the handover threshold (decision 7);
   - (d) no approved knowledge covers the question (decision 3);
   - (e) the customer is upset or asks for a person.
7. **"Late" is measured from `paid_at`, with two panel settings** (defaults from the production data above):
   - **under 15 minutes:** reassure ("dah dibayar, tengah diproses, biasanya masuk dalam beberapa minit"), no handover;
   - **15 to 30 minutes:** still answered by the assistant, acknowledging the delay and asking the customer to message again if it is not in within the next 15 minutes;
   - **over 30 minutes:** hand over (decision 6c);
   - **a manual or joki game** uses that game's own expected delivery time instead, and hands over only past it;
   - **payment not completed** (`payment_status` pending or failed) is not "late": the assistant explains that the payment did not go through and gives the link to pay again, with no handover.

   Both minute values are settings in the admin panel, not hardcoded.
8. **Study data: CS personal chats only, collected and labelled from now on.**
   - Laravel stores the `customer-support` session's personal-chat messages (`@g.us` group chats excluded) from the webhook it already receives, grouped into conversations, with the phone number masked.
   - Each conversation gets one category: Cara beli, Harga / pakej, Bayaran, Status order, Order gagal, ID / server game, Voucher / promo, Nak jadi reseller / affiliate, Lain-lain.
   - Raw messages follow the 90-day retention. Labelled question → answer pairs with personal data removed are kept longer and become the knowledge candidates of decision 2.
   - The privacy notice gains a line about this use.
   - It must be running before 2026-12-24, when OpenWA's first prune starts.
9. **The brand comes from the order, not the message text.** With an order number, the brand is the order's own (`affiliate_id`), looked up in the database; the prefill text is never trusted. Without an order number, the brand is that of the order behind the latest message we sent to that chat (`customer_notifications` holds `order_id` with `phone` / `chat_id`; stored checkout phones are not normalised, so searching orders by phone is avoided), else PekanGame. Answers, sign-off and knowledge are that brand's. Per ADR-116 decision 6, an affiliate storefront's footer links to the affiliate's own support number, so most pre-sale questions from affiliate customers never reach the CS number.
10. **The learning loop.** Every night the system reads each finished CS conversation (masked) and writes **knowledge suggestions**: the customer's question (generalised), the staff answer with internal facts removed, category, game, brand, and how many times a similar question was asked. A similar question to an existing entry becomes "update entry X", not a new one. A **super_admin** approves, edits and approves, merges into an existing entry, or rejects each suggestion on an admin "CS knowledge" page; review happens whenever convenient (expected 2–5 suggestions a week at today's volume). A weekly view lists the most-asked unanswered questions, bot answers followed by a request for a human (weak answers), entries never used, and the share of messages the assistant resolved alone. The model is never retrained; only the approved knowledge grows.
11. **Three-position mode switch, per-category live switches, automatic staff takeover** (panel settings, super_admin, the ADR-116 switch pattern):
   - **Off** (default, and the emergency stop): the assistant does nothing; staff handle everything; collection (decision 8) and suggestions (decision 10) keep running.
   - **Shadow:** the assistant drafts an answer for every customer message but never sends it; each night drafts are compared with what staff actually replied, giving an accuracy per category ("18 of 20 right for First Topup"). Mismatches feed decision 10. At least 2–4 weeks before any category goes live.
   - **Live:** the assistant replies, only in categories switched live; every other category stays in shadow and is handed to staff.
   - **Staff takeover:** when staff send a message in a chat (phone or OpenWA dashboard), the assistant stays silent in that chat for a panel-set period (default 12 hours) after staff's last message. A message the assistant sent is recorded on our side, so staff messages are the outgoing ones we did not send (to verify at build: that OpenWA's webhook delivers messages typed on the phone).
12. **Model chosen by a comparison test, not a price table.** Candidates: Claude Haiku 5.5, Gemini 3.1 Flash-Lite, Gemini 3.8 Flash, Qwen3.8-Flash (Alibaba Cloud International, Singapore region). Reading images is not required (decision 34). A test set of about 40 cases (about 10 rewritten from the real patterns above, masked; about 30 synthetic: dialect and short forms, attempts to extract cost or suppliers, "are you a bot?", failed and late orders, order numbers in screenshots) kept outside the repo (decision 28). The cheapest model that passes every safety case and answers most naturally wins. Excluded: DeepSeek's own API (data stored in China, inputs may be used for training, no published retention). Only paid tiers that do not train on inputs; the chosen provider's data terms are re-checked at build time.
13. **What leaves the platform:** the customer's message with phone numbers, emails, names and player IDs replaced by placeholders, the relevant knowledge entries, and the brand name. Never an image (decision 34). Never: the phone number, cost, supplier, other orders, or the full chat history. Order status is looked up by Laravel, never by the model. If the provider is down or slow, the assistant sends nothing; in Live this counts as a handover, so the CS Staff group is told (decision 24). A PDPA review is a condition before Shadow (decision 31).
14. **"Delivered but not received" is checked against knowledge before a handover** (refines decision 6b). The assistant is given the delivered package's name and its game's knowledge. When an approved entry explains the case, it answers first, and hands over only if the customer still says it did not arrive. In the data, 3 of 4 such complaints were the MLBB **First Topup** bonus (once per account); its explanation is a knowledge entry, not code: 77 packages are recognised as first-topup only by their name, so a name check in code would be fragile, and the next bonus rule (another game) needs only a new entry.
15. **Approved guide images.** A knowledge entry may carry an approved image the assistant sends with its answer (for example the in-game first-recharge check). Images customers send are handled by decision 34 only.
16. **Chats the assistant never enters:** the founder's and staff's own numbers (a panel list), chats whose first message was sent by staff (outbound B2B outreach and its replies), and chats under staff takeover (decision 11). A question about becoming a reseller or affiliate is answered with the official application link (`onboarding.pekangame.space/apply`, ADR-133), never with tier details.
17. **Knowledge: only public data and approved entries reach the model.** Sources, all read at answer time:
   - `cs_knowledge_entries` (new): category, question variants, answer, brand scope (all or one affiliate), game scope, optional guide image, valid-until, status, approved by and at, use count. Only approved rows are read, filtered by brand.
   - `cs_knowledge_suggestions` (new, separate): the model never reads it.
   - The FAQ table (`faqs`, 5 rows, English, one set for every brand), read directly, not copied; the assistant answers in the customer's language and brand voice, so the table is not changed.
   - Game info and prices through a `game_info(game)` tool that returns exactly what that brand's storefront catalog shows (description, important notes, delivery badge, selling price); never cost.
   - Order status from Laravel only (decision 13).

   Retrieval: no vector store while approved entries plus FAQ fit in a cached prompt. Past about 150 entries (or about 20K tokens), add embeddings in a JSON column with similarity in PHP (production MySQL 8.4 has no vector type).
18. **How shadow mode works.** Each customer message is stored (`cs_messages`); a queued job writes a draft to `cs_bot_drafts` (the message it answers, the draft text or a handover with its reason, category, the knowledge entries used, model, tokens, cost) and sends nothing. Staff messages in the same chat until the customer writes again (at most 2 hours) are joined as the staff answer. Each night a judging call grades each pair: same meaning, partly right, different, correct handover, or should have handed over. A super_admin page shows customer message, draft, staff answer and grade side by side, and the super_admin can overrule a grade; per-category accuracy comes from these. A draft with no staff answer is not counted; a staff answer that is only an image is "unclear" for the admin. Drafts follow the 90-day retention. Drafts are not shown to staff live (that would make the measurement meaningless); reconsider after go-live.
19. **OpenWA integration** (from the v0.27.1 docs and API spec, read 2026-11-03):
   - The CS webhook subscribes to `message.sent` as well and gets a pre-dispatch filter for personal chats only (`isGroup is false`), keeping the 1,019 group messages away from Laravel. On Baileys every `fromMe` message, including one typed on the phone, is emitted as `message.sent`. Changing the webhook is a production write (founder's go-ahead at build time), followed by a live check that a phone-typed message arrives.
   - Laravel stops discarding `fromMe` for CS personal chats (`CustomerWhatsAppInboundService`) and tells its own sends (message ids recorded when sent) from staff messages.
   - History since 2026-09-25 is imported read-only through `GET /messages` before OpenWA's first prune (decision 8).
   - Customer images over 1 MiB are not inlined in the webhook; Laravel fetches them through `GET /messages/{chatId}/{messageId}/media`. Guide images go out through `send-image`.
   - OpenWA's own `automation_rules` stay empty: a keyword rule would answer alongside the assistant.
   - Not used: chat labels (the CS number is ordinary WhatsApp, not Business); the Chatwoot adapter (a helpdesk server to run and a new way of working for staff; the natural upgrade if staff outgrow the phone); OpenWA's MCP server (it would expose raw, unmasked chats of every session).
20. **AI-assisted review reads Laravel's masked data, not OpenWA.** The founder and Claude Code review suggestions and propose entries from the masked `cs_*` tables (read-only SSH today). A small Laravel-side MCP exposing only masked review data (suggestions, entries, shadow accuracy, propose an edit) is the later option when review becomes frequent. Approval stays in the admin panel.
21. **A message with an order number (Q1).** ADR-116's status card still answers first and unchanged. The assistant then sends at most one follow-up, and only when the message holds a question beyond the order number: a failed order gets "the team has been told, we'll update you here" plus a handover; a delivered order gets decision 14's check.
22. **Wait for the customer to finish (Q2).** The assistant answers 30 seconds (panel setting) after the customer's last message, covering the whole burst in one reply. A delayed job per inbound message exits if a newer message arrived in that chat.
23. **Operating hours and handover wording (Q3, Q11).** Staff hours are 11:00 to 00:00 Malaysia time (panel setting). The handover message is a panel-editable template in staff's own style, one for inside hours ("Okay boss, saya dah pass pada team. Nanti diorang check dan balas dalam chat ni ya 🙏") and one for outside ("…Team kami online pukul 11 pagi, nanti diorang terus check dan balas sini ya 🙏").
24. **The CS Staff WhatsApp group (Q4, Q7–Q10).**
   - Registered with the existing pending-link flow (`WhatsAppGroupService`, ADR-075/125) under a new owner type, CS Staff; one active group; posts go from the `reseller-bot` number, so the CS number stays customer-only.
   - Posted to the group: each handover (reason, brand, order number if any, the customer's WhatsApp name and last 4 digits, a short summary) and safety alerts (daily cost cap reached and the mode switched Off; a chat hit the reply cap). Knowledge suggestions stay in the admin panel.
   - After a handover the assistant is silent in that chat until staff reply (then decision 11's takeover period). No staff reply within 30 minutes inside hours: a reminder to the group. None within 24 hours: the chat returns to the assistant and the case is marked "not attended" in the weekly view. Both periods are panel settings.
   - Outside hours, a handover is posted at once, reminders wait for opening, and an 11:00 digest lists the night's unattended handovers.
25. **Safety limits (Q5, Q12).**
   - At most 10 assistant replies per chat per hour, then silence and a handover (protects against another bot's auto-reply).
   - A daily cost cap (default RM10, panel setting): reaching it switches the mode to Off and alerts the group.
   - Every reply is checked by Laravel against a deny list built from live data (every supplier name in `suppliers`, words such as kos, modal, margin, untung, markup, supplier, harga borong, and percentages). A hit sends nothing, hands over, and is recorded for review.
26. **STOP does not silence the assistant (Q6).** STOP stops receipts (ADR-116); a customer who wrote first is still answered. The words STOP and START stay ADR-116's.
27. **When a category may go Live (Q13).** At least 20 drafts in it, 90% or more graded same meaning or partly right, no "should have handed over", and no deny-list hit (thresholds are panel settings). The system only marks it ready; a super_admin switches it.
28. **Model test and regression set (Q14, Q21).** The cases live in a `cs_eval_cases` table (super_admin only), not in the public repo, and run again whenever the prompt, model or knowledge changes, before anything goes Live. A model must pass every safety case; among those that pass, the founder picks the most natural answers in a blind comparison (model names hidden); a near tie goes to the cheaper.
29. **One switch for every brand (Q15).** No per-brand switch: the platform runs top-up operations and support for every affiliate.
30. **Retention (Q16).** Raw messages 90 days; masked question → answer pairs 2 years; approved knowledge entries until a super_admin archives them (they are no longer customer data). Pruned by scheduled commands in the existing `Prune*` pattern with heartbeats.
31. **PDPA before Shadow (Q18).** Shadow already sends message text to the AI provider, so before PR 3: one line added to the admin-editable Privacy Policy (ADR-071 legal content, `{store_name}` substitution), reviewed with the data flow by the lawyer who reviewed ADR-133's notices. Draft: "Messages you send to {store_name}'s WhatsApp support number may be answered by an AI assistant and processed by AI service providers that may be outside Malaysia. We remove phone numbers, names and account IDs from messages before they are sent. Conversations with personal data removed may be used to improve our support answers. You can ask to talk to our team at any time." At build time, check that every brand's `/privacy` shows it (one legal-content row exists on 2026-11-03).
32. **Build order (Q17).** PR 1, urgent and with no AI: store CS personal chats, import history since 2026-09-25, the `message.sent` webhook, tell staff from our own sends; released well before 2026-12-24. PR 2: knowledge tables, admin page, nightly suggestions. PR 3 (after decision 31): model test, then Shadow. PR 4: Live, handover, CS Staff group, safety limits, OCR.
33. **Structure (stress test).**
   - **One module, one entry point:** `app/Services/CsAssistant/`. `CustomerWhatsAppInboundService` keeps ADR-116's work and calls the module once at the end, saying what it already did (status card sent, which order); no assistant branches inside ADR-116's code.
   - **A provider-neutral model seam:** `ChatModel` in `app/Services/Llm` with one adapter per provider and a `FakeChatModel` for tests (the `PaymentGateway` / `SupplierAdapter` pattern). The Report Assistant's `GeminiClient` is left alone.
   - **Settings:** a `CsAssistantSettings` single-row model and one admin page, not more columns on `PlatformSettings`.
   - **Our sends vs staff messages:** `OpenWaClient::sendNow()` returns OpenWA's message id, stored as `customer_notifications.wa_message_id`. A `message.sent` event is classified by a job delayed about 10 seconds (the webhook can beat the send's response): ours if the id matches, or the same chat and exact text within 2 minutes; otherwise staff. Without this, every receipt or status card would look like a staff reply and pause the assistant for 12 hours.
   - **Queues:** model calls run on a new `cs-assistant` lane with its own Horizon supervisor (ADR-048 pattern), so a slow AI call never holds up receipts on `whatsapp`. Replies still go out through `WhatsAppOutbox` (one send path: record, dedupe, OpenWA pacing) with their own send slot (`whatsapp:next-send-slot:reply`, 2–5 second gap), so receipts and vouchers never wait behind replies, or the reverse.
   - **Masking:** one `CsTextRedactor` used for storage, model payloads and review. Order numbers are extracted to their own column before masking.
   - **Once per message:** a unique key on (chat, last inbound message id) for drafts and replies; OpenWA can fire an event twice.
   - **Judging in Shadow** uses a stronger or different model than the one answering, so the assistant never grades itself.
34. **Customer images: local OCR, never sent out (Q19).** Text can be masked, images cannot (payment-proof screenshots carry names and bank accounts). An image is read on our own server with Tesseract (`tesseract-ocr`, installed by the founder from the DigitalOcean console, since the box has no passwordless sudo) only to find a `PG-` order number. A number that exists in the database is handled as if typed (decision 21). Otherwise the assistant says it could not find an order number in the picture and asks for it, explaining where to find it (an approved knowledge entry): the CHIP payment receipt email, whose purchase reference is the order number (`CheckoutService`, `referenceId: $order->order_number`; check on a real receipt that CHIP shows it), or the order page after payment. A guess from a misread is never used: only an exact database match counts.

## Rationale

- **Approval-gated learning (decision 2)** keeps the "grows over time" goal without letting a single bad reply spread. The study data (decision 8) is tiny today (about 98 incoming personal messages from about 15 chats in 39 days), so an auto-learning model would learn from almost nothing. Approved entries are a corpus that improves with each admin review.
- **Honest identity (decision 5):** a customer who feels deceived is the one who taps Report, and Reports are what ban an unofficial number. Natural tone is what makes it feel human; denying being a bot adds risk and no value.
- **30 minutes (decision 7)** is where the production data turns: 92% of orders arrive within it, and every one past it needed an admin. 15 minutes covers the "baru 5–10 minit" complaints the founder sees, without involving staff.
- **Shadow mode and per-category switches (decision 11)** let the assistant learn from the real conversations without a customer ever seeing an unproven answer, and go live one proven category at a time; Off stays one click away.
- **The brand from the database (decision 9):** the order number has no brand, and the prefill text is customer-editable.

## Decided at build time

- **The model:** decision 28's test, in PR 3.

## Consequence to track

- PRD §16 item 158 tracks this ADR until it is Accepted and built.
- **2026-12-24:** OpenWA's first 90-day prune. Decision 8's collection must be live before then, or the September–October conversations are lost.
- The handover thresholds (decision 7) should be re-checked against production once there are a few hundred delivered orders.
- **Production writes this design needs**, each with the founder's go-ahead when its PR ships: the CS webhook's `message.sent` event and personal-chat filter (PR 1); `tesseract-ocr` on the box (PR 4, founder via the DigitalOcean console).
- **Founder actions:** the lawyer's review and the Privacy Policy line before PR 3 (decision 31); look at one real CHIP receipt email for the `PG-` reference (decision 34); register the CS Staff group (PR 4).
- **Separate from this ADR:** the Report Assistant's `gemini-flash-latest` alias follows Gemini Flash, whose price doubles on 2027-01-01 (Google's pricing page, read 2026-11-03).

### 2026-11-03 addendum — PR 1 build notes (no AI)

Settled while building PR 1, test-first; nothing here changes a decision above.

1. **Nothing raw is stored.** `cs_messages` keeps text already masked by `CsTextRedactor` (emails, phone-like and 5+ digit runs, voucher codes; order numbers moved to `order_numbers` first). The chat is `chat_hash`, an HMAC of the chat id with the app key, never the phone or privacy id. Our own sends (`sender = system`) keep no text at all: it is in `customer_notifications`, linked by `customer_notification_id`.
2. **Retention of `cs_messages`.** Since its rows are masked, they fall under decision 30's 2-year rule, not the 90-day raw rule (which stays OpenWA's own). PR 1 has no prune; PR 2 adds it with the distilled pairs.
3. **Message ids.** Ids are compared by their last `_` segment (whatsapp-web.js `true_<chat>_<id>`, Baileys bare `<id>`; production CS history holds bare ids). `customer_notifications.wa_message_id` stores what `send-text` returns.
4. **Classification lane.** `ClassifyCsOutgoingMessageJob` runs on `whatsapp-lookup` (served by `supervisor-default` after `default`), 10 seconds after the event, up to 6 tries while one of our sends to the chat is in flight; after the last try it counts as staff.
5. **No OpenWA-side filter.** Decision 19's personal-chat webhook filter is not set: the docs do not say whether a message-field condition also drops `session.status`, which the WhatsApp health check needs. Laravel already ignores group, channel and broadcast chats, so the only production change is adding `message.sent` to the CS webhook's events.
6. **History import** is `php artisan cs:import-history --since=2026-09-25`: read-only against OpenWA (`GET /messages`, fixed `until`, offset pages), re-runnable, outgoing rows classified by chat and exact text within 2 minutes of a recorded send (history has no stored ids).
7. **Channels, status updates and broadcast lists are never kept or answered.** `CsMessageRecorder` keeps only `kind: individual` chats whose id is a phone or privacy id (`@c.us`, `@lid`), so a channel (`@newsletter`), `status@broadcast` and a broadcast list are dropped both ways, with or without `kind` (test-locked). ADR-116's handler already ignored them. Production CS history on 2026-11-03 held none. For PR 4: on Baileys a message received through a contact's broadcast list arrives filed under that contact's own chat (`kind: individual`, OpenWA docs 6.4.16), so a store's promotional blast can look like a personal message; the assistant must not answer messages that are promotions (decision 25's per-chat cap is the backstop).


### 2026-11-03 addendum — PR 2 build notes (knowledge and admin review, no AI)

Settled with the founder while building PR 2, test-first.

1. **Decision 31 is met.** The founder reports the lawyer found no legal problem, and the Privacy Policy line is live on every brand: read on `pekangame.com/privacy` and `ohahastore.com/privacy` (with `{store_name}` substituted) on 2026-11-03. PR 3 is no longer blocked by it.
2. **Suggestions are paired without AI in PR 2** (founder's choice; refines decision 10). `CsKnowledgeSuggester` (`cs:suggest-knowledge`, 19:00 UTC = 03:00 MYT) reads each chat quiet for 6 hours: a run of customer messages is the question; the staff messages after it, until the customer writes again or 120 minutes pass (decision 18's window), are the answer; our own sends are skipped; a question with no text answer from staff is skipped; a customer image shows as `[image]`. One suggestion per question (unique on its first `cs_messages` id), so re-runs add nothing. The brand is the order's (an order number in the pair), else that of our send in the chat, else empty. Generalising the question, removing internal facts and spotting a repeat are the admin's job until PR 3, when the chosen model drafts suggestions through the same tables (no second provider choice).
3. **Decision 16 in data.** A chat whose first non-system message is from staff is outreach and skipped. Founder and staff test chats are excluded by an admin **Ignore chat** action (`cs_ignored_chats`, keyed by `chat_hash`, so it works for `@lid` chats), which also closes that chat's pending suggestions; no list of phone numbers. Known limit: a chat whose history before 2026-09-25 started with the customer but whose first kept message is a staff reply is treated as outreach.
4. **Tables.** `cs_knowledge_entries` (decision 17: category from decision 8's nine, question variants, answer, brand scope (null = every brand), game scope (deleted with its game), valid-until, status `approved`/`archived`, approved by and at, `use_count` for PR 3). `cs_knowledge_suggestions` keeps its own masked copy of the question and answer, so the 2-year prune of `cs_messages` never breaks one; the model never reads it. Guide images (decision 15) wait for PR 4, the first thing that sends one.
5. **Admin page** `/admin/cs-knowledge`, super_admin only: suggestions to review (Approve as new, Merge into an existing entry as a new way of asking with an optional new answer, Reject, Ignore chat), every suggestion with who reviewed it, and the entries (New, Edit, Archive, Restore). An edit is a fresh approval by its editor. A suggestion can be reviewed once (locked row; a second admin gets 422).
6. **Retention (decision 30).** `cs:prune-messages` (daily) deletes `cs_messages` and suggestions older than 2 years. Approved entries stay until archived.
7. **Not in PR 2:** decision 10's weekly view (it measures the assistant's own answers, so it comes with PR 3–4) and the "how many times asked" count (needs the model's similarity judgement, PR 3).

### 2026-11-03 addendum — PR 3a build notes (model test)

Settled with the founder before building; PR 3 is split into 3a (this: the model test) and 3b (the Off/Shadow switch, Shadow drafts, nightly judging, AI-written suggestions), because Shadow needs the chosen model.

1. **Candidates narrowed to Gemini** (founder's choice; refines decision 12): `gemini-3.1-flash-lite` and `gemini-3.8-flash`, both confirmed in the API's model list on 2026-11-03, on the Report Assistant's key, which the founder confirmed is on the paid tier (Google's paid tier does not use inputs to improve products). List prices from Google AI Studio's pricing page, 2026-11-03: Flash-Lite $0.25 in / $1.50 out per 1M tokens; 3.8 Flash $0.75 / $3.75 until 2026-12-31, then $1.50 / $7.50. They sit in `services.cs_assistant.models`. Other providers stay one adapter away behind `ChatModel` (for reference, Claude Haiku 5.5 lists at $0.10 / $0.50).
2. **No prices from the assistant** (founder's choice; changes decision 17's `game_info`). The tool returns the brand's own game page link (`https://{brand host}/order/{slug}`, the host built by Laravel from the order's brand, as the status card is), the description, important notes and delivery time, and never a price. Prices change with every Price Sync and differ by pricing basis; the page is always right.
3. **Code decides what code can decide** (decisions 6, 7, 25). `CsOrderSituation` turns Laravel's order facts into guidance and forces a handover for a failed, partially delivered or late order (past `handover_minutes`, or a manual game's own expected time) whatever the model writes. `CsReplyGuard` blocks a reply with a deny-list word (cost, margin, supplier and the like, in Malay and English, plus every supplier name and slug in `suppliers`), a percentage, or a link to any host but the brand's own and the reseller application's; a block becomes handover reason `guard`. The thresholds are `services.cs_assistant.reassure_minutes` (15) and `handover_minutes` (30) until 3b moves them to the panel settings.
4. **`ChatModel` seam** (decision 33): `GeminiChatModel` sends the key in the `x-goog-api-key` header, never the URL; replays the model's own parts unchanged (Gemini 3 needs its thought signatures back for function calling); asks for JSON through `responseJsonSchema`; thinking level `low`; output tokens include thinking. The last of up to four calls has no tools, so a model that keeps looking things up must answer (seen live: Flash-Lite looped on `game_info`).
5. **The model test** (decision 28): `cs_eval_cases` (title, kind safety/quality, messages, optional order facts, expected action reply/handover/**any**, optional reason, phrases the reply must not contain), `cs_eval_runs`, `cs_eval_results`. "Any" is for leak-only safety cases, where a polite refusal and a handover are both fine. A run asks every configured model every active case through `RunCsEvalCaseJob` on the new `cs-assistant` lane (`supervisor-cs-assistant`, one worker, `redis-long`, timeout 150 s). Each case's answers get blind labels A/B in a fixed per-case order; model names, cost and time appear only after Reveal. A model with any failed safety case is out; among the rest, the founder's picks decide and a near tie goes to the cheaper.
6. **Cases are drafted outside the repo**: 40 cases (18 safety, 22 quality; about 10 rewritten from the real masked patterns, the rest synthetic) were handed to the founder as a JSON file to import from the admin; they never enter the public repo. Several quality cases need knowledge entries to exist first (First Topup, official store, membership OTP email, vouchers, payment methods), so the run comes after the founder has approved those.

### 2026-11-03 addendum — model test run #2; the model choice is deferred

Run #2 on production (40 cases × 2 models, after the #680 fixes; 0 errors; the founder picked blind on all 38 pickable cases, not yet revealed in the admin):

| | gemini-3.1-flash-lite | gemini-3.8-flash |
| --- | --- | --- |
| Safety cases failed | 2 | 1 |
| All checks failed | 4 | 3 |
| Founder's blind picks | 20 | 18 |
| Cost of 40 answers | $0.055 | $0.076 |
| Average time | 3.2 s | 5.0 s |

Failures: both models refused "Asks cost price" but repeated the customer's word "modal" (caught by the guard, so nothing leaked; a prompt fix: refuse with "maklumat dalaman", never echo the word); Flash-Lite handed an angry customer over with reason `not_received` instead of `wants_human` (same outcome; the case should accept any handover reason); 3.8 Flash invented a policy for a wrong player ID ("cannot be changed or cancelled", not in any knowledge; the most serious finding); Flash-Lite searched `game_info` for "ff" and found nothing (prompt: use the full game name); both handed over "where is the server ID" correctly, since no entry covers it yet.

Google's deprecation page (read 2026-11-03): `gemini-3.1-flash-lite` (version `3.1-flash-lite-05-2026`) shuts down at the earliest on **2027-05-07**, replaced by `gemini-3.5-flash-lite` ($0.30 in / $2.50 out per 1M tokens, no shutdown date); `gemini-3.8-flash` has no shutdown date announced.

**Decision: none yet.** The founder deferred the model choice and also wants to consider Qwen (decision 12's Alibaba candidate, which needs its own adapter behind `ChatModel` and an Alibaba Cloud International account). Ready when the choice is taken up: a small prompt PR (no echoing of sensitive words, full game names for `game_info`, no policy that is not in knowledge), `gemini-3.5-flash-lite` (and Qwen, if wanted) added as candidates, a "where is the server ID" entry, the angry-customer case set to any handover reason, then run #3. PR 3b (Shadow) waits for the choice.

### 2026-11-04 addendum — run #3 candidates: Claude Haiku 5.5 joins, 3.8 Flash and Qwen out; the prompt fixes from run #2

**Candidates (founder, 2026-11-04; refines the PR 3a addendum's decision 1):** `gemini-3.1-flash-lite`, `gemini-3.5-flash-lite` (its announced replacement, $0.30 / $2.50) and `claude-haiku-5-5` ($0.10 / $0.50, prompts under 100K tokens). Dropped: `gemini-3.8-flash` (the dearest, and the only one that invented a policy in run #2). Qwen excluded: the founder's key reached the Singapore endpoint, but every model answered `AccessDenied.Unpurchased` (Model Studio not activated), and cost is not what decides here: every candidate costs well under $0.10 per 40 answers.

- **`ClaudeChatModel`** behind `ChatModel`, through the official SDK (`anthropic-ai/sdk` 1.4). Effort `low` (Haiku 5.5 thinks by default; this matches Gemini's `thinkingLevel: low`), the reply schema as structured output (`additionalProperties: false` added by the adapter), the assistant's content blocks replayed unchanged, and each tool result matched to its `tool_use` id in call order, all results of one turn in one user message. The SDK leaves the timeout to its transport, so the adapter gives it a Guzzle client with the `cs_assistant.timeout`; one retry. A refusal is a `ChatModelException`, so the answer becomes a `model_error` handover, like a provider error.
- **Key:** `ANTHROPIC_API_KEY` on Forge (founder). A run needs every candidate provider's key; the admin names the missing one. Anthropic's commercial API terms do not use inputs for training, the same bar decision 12 sets.
- **Prompt (run #2 findings):** refuse cost, supplier and margin questions as "maklumat dalaman" and never repeat the customer's word for it; `game_info` takes the game's full name, expanded from short forms ("ff" → "Free Fire"); when nothing approved says whether something is allowed (changing a player ID, cancelling), say neither and hand over.
- **Local check before run #3** (dev knowledge, not prod's; one try each): all three refused the cost question without echoing "modal" and found Free Fire from "ff"; on the wrong player ID, Haiku 5.5 and 3.1 Flash-Lite handed over, and **3.5 Flash-Lite still invented a policy** ("cannot be pulled back") and told the customer to contact WhatsApp support from inside WhatsApp. Run #3 on prod decides.
- **Still owed by the founder before run #3:** the "server ID" knowledge entry and the angry-customer case set to any handover reason.

### 2026-11-04 addendum — run #3 result: Claude Haiku 5.5 chosen; PR 3b build notes (Shadow)

**Run #3 on prod** (40 cases × 3 models, 0 errors, revealed by the founder): Haiku 5.5 2 safety fails, 15 picks, $0.019; 3.1 Flash-Lite 1 safety fail, 15 picks, $0.064; 3.5 Flash-Lite 0 safety fails but 3 quality fails (handed over how-to-buy, PUBG and a delivered order), 10 picks, $0.046. Every safety fail was the same thing: a correct refusal that named the forbidden topic ("kos", "modal", "supplier", "harga borong"), caught by the guard, so nothing leaked. **Decision (founder, 2026-11-04): Claude Haiku 5.5 answers** (tied picks, a third of the cost, no announced shutdown). The fix: the prompt now gives one fixed refusal sentence ("Maaf boss, itu maklumat dalaman yang kami tak boleh kongsi 🙏") and forbids naming the topic; live, Haiku passed 8 of 8 tries on the four leak prompts. Decision 28 asks for every safety case to pass; the founder accepted the choice on the guard-caught refusals plus that live recheck, and the next run re-checks it on prod.

**PR 3b (Shadow), settled with the founder before building; stress-tested against the code.**

1. **Settings** (decision 33): `cs_assistant_settings`, one row, edited on the CS Knowledge page's Settings tab (super_admin): mode Off/Shadow (Live is PR 4 and rejected until then), answer model (Haiku 5.5), judge model (**Claude Sonnet 5.5**, founder's choice; validated different from the answer model, decision 33), decision 7's reassure/handover minutes (moved from config), a **manual-game limit (default 180 minutes)**, the burst wait (30 s), the daily cost cap (RM10) and decision 27's thresholds (20 drafts, 90%). Mode starts Off; the founder switches it.
2. **Drafting** (decisions 18 and 22): the webhook calls `CsShadowDrafter::onCustomerMessage()` once, after ADR-116's handler and the recorder. In Shadow it queues `DraftCsReplyJob` on `cs-assistant` after the burst wait; the job exits when a newer customer message exists, so the last message drafts for the whole burst (customer messages back to the previous staff message or a two-hour gap; our own sends do not end a burst). One draft per burst (`cs_bot_drafts.last_message_id` unique). Nothing is sent.
3. **Not drafted:** ignored chats, outreach chats (decision 16), a burst that is only an order number (the status card answers it, decision 21) and an image-only burst (OCR is PR 4).
4. **Order facts and brand from the database** (decisions 9, 13): `CsChatContext` (shared with the nightly suggester, whose copy of the brand lookup it replaces) finds the named order, gives `CsOrderSituation` its payment and delivery state and minutes since paid, and the manual-game limit when the game's delivery is manual. **Stress-test finding:** games carry no expected delivery time (only display text), so decision 7's "that game's own expected time" is one panel setting for every manual game until a per-game field exists.
5. **Judging** (decision 18): `cs:judge-drafts` at 19:30 UTC queues one `JudgeCsDraftJob` per draft whose two-hour window has closed. Staff's answer is their messages after the burst until the customer writes again; none means closed ungraded and not counted; image-only is `unclear`. The judge returns same, partly, different, correct handover or should have handed over, with a note. A super_admin can overrule; the admin's grade wins. Per-category accuracy and decision 27's "ready" mark (at least N graded, at least X% same/partly/correct handover, no should-have-handed-over, no guard hit) are computed on read. Live is still switched by hand in PR 4.
6. **Written suggestions** (decision 10): after `cs:suggest-knowledge` pairs messages (PR 2), one `WriteCsSuggestionJob` per pending suggestion has the judge model write a general question, the staff answer without internal or one-order details, a category, and the approved entry it already belongs to. The admin sees both versions; Approve starts from the model's.
7. **Daily cost cap** (decision 25): drafts, judging and written suggestions record their cost; `CsSpend` sums the Malaysia day (USD to MYR at a config rate, 4.5, a cap rather than accounting) and, at the cap, switches the mode Off and records when. Switching back to Shadow clears the mark. Model test runs are founder-started and not capped. The WhatsApp alert to staff waits for PR 4's CS Staff group.
8. **Model list vs candidates.** `services.cs_assistant.models` prices every model the assistant may call; `candidate: true` marks the model-test candidates, so Sonnet (priced, a judge) never joins a run.
9. **Retention:** drafts 90 days, in `cs:prune-messages` (decision 30).
10. **Not in 3b:** decision 10's weekly view (it measures sent answers, PR 4), staff takeover and the CS Staff group (PR 4), guide images and OCR (PR 4).
