# ADR-136: Customer-support assistant on the CS WhatsApp number — answers from approved knowledge, learns only through admin approval, hands order problems to a human

> **Standing (2026-11-03):** Proposed. Extends [ADR-116](./ADR-116-customer-order-notifications-over-whatsapp.md) (the CS session, opt-in, status card, STOP/START) and [ADR-075](./ADR-075-reseller-bot-channel.md) (OpenWA). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Proposed, 2026-11-03. Decisions 1–20 were made with the founder in Malay on 2026-11-03. Still open before Accepted: the model choice from the comparison test (decision 12), and a grill plus stress test of the whole design. PRD §16 item 158.

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
9. **The brand comes from the order, not the message text.** With an order number, the brand is the order's own (`affiliate_id`), looked up in the database; the prefill text is never trusted. Without an order number, the brand is that number's most recent order's brand (`whatsapp_contacts`), else PekanGame. Answers, sign-off and knowledge are that brand's. Per ADR-116 decision 6, an affiliate storefront's footer links to the affiliate's own support number, so most pre-sale questions from affiliate customers never reach the CS number.
10. **The learning loop.** Every night the system reads each finished CS conversation (masked) and writes **knowledge suggestions**: the customer's question (generalised), the staff answer with internal facts removed, category, game, brand, and how many times a similar question was asked. A similar question to an existing entry becomes "update entry X", not a new one. A **super_admin** approves, edits and approves, merges into an existing entry, or rejects each suggestion on an admin "CS knowledge" page; review happens whenever convenient (expected 2–5 suggestions a week at today's volume). A weekly view lists the most-asked unanswered questions, bot answers followed by a request for a human (weak answers), entries never used, and the share of messages the assistant resolved alone. The model is never retrained; only the approved knowledge grows.
11. **Three-position mode switch, per-category live switches, automatic staff takeover** (panel settings, super_admin, the ADR-116 switch pattern):
   - **Off** (default, and the emergency stop): the assistant does nothing; staff handle everything; collection (decision 8) and suggestions (decision 10) keep running.
   - **Shadow:** the assistant drafts an answer for every customer message but never sends it; each night drafts are compared with what staff actually replied, giving an accuracy per category ("18 of 20 right for First Topup"). Mismatches feed decision 10. At least 2–4 weeks before any category goes live.
   - **Live:** the assistant replies, only in categories switched live; every other category stays in shadow and is handed to staff.
   - **Staff takeover:** when staff send a message in a chat (phone or OpenWA dashboard), the assistant stays silent in that chat for a panel-set period (default 12 hours) after staff's last message. A message the assistant sent is recorded on our side, so staff messages are the outgoing ones we did not send (to verify at build: that OpenWA's webhook delivers messages typed on the phone).
12. **Model chosen by a comparison test, not a price table.** Candidates: Claude Haiku 5.5, Gemini 3.1 Flash-Lite, Gemini 3.8 Flash, Qwen3.8-Flash (Alibaba Cloud International, Singapore region), each able to read images. A test set of about 40 cases (about 10 rewritten from the real patterns above, masked; about 30 synthetic: dialect and short forms, attempts to extract cost or suppliers, "are you a bot?", failed and late orders, screenshots) kept outside the repo. The cheapest model that passes every safety case and answers most naturally wins. Excluded: DeepSeek's own API (data stored in China, inputs may be used for training, no published retention). Only paid tiers that do not train on inputs; the chosen provider's data terms are re-checked at build time.
13. **What leaves the platform:** the customer's message with phone numbers, emails, names and player IDs replaced by placeholders, the relevant knowledge entries, the brand name, and an image the customer sent. Never: the phone number, cost, supplier, other orders, or the full chat history. Order status is looked up by Laravel, never by the model. If the provider is down or slow, the assistant stays silent and the chat is left to staff. A PDPA review (cross-border transfer, the privacy-notice line of decision 8) by the same lawyer as ADR-133's notices is a condition before Live, not before build.
14. **Check First Topup before handing over a "Delivered but not received" order** (refines decision 6b): when the delivered package is a first-topup bonus package, the assistant explains the once-per-account bonus and how to check it in game first, and hands over only if the customer still says it did not arrive. In the data, 3 of 4 such complaints were this.
15. **Approved guide images.** A knowledge entry may carry an approved image the assistant sends with its answer (for example the in-game first-recharge check). Images a customer sends are read only to understand the question, never as proof of payment.
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

## Rationale

- **Approval-gated learning (decision 2)** keeps the "grows over time" goal without letting a single bad reply spread. The study data (decision 8) is tiny today (about 98 incoming personal messages from about 15 chats in 39 days), so an auto-learning model would learn from almost nothing. Approved entries are a corpus that improves with each admin review.
- **Honest identity (decision 5):** a customer who feels deceived is the one who taps Report, and Reports are what ban an unofficial number. Natural tone is what makes it feel human; denying being a bot adds risk and no value.
- **30 minutes (decision 7)** is where the production data turns: 92% of orders arrive within it, and every one past it needed an admin. 15 minutes covers the "baru 5–10 minit" complaints the founder sees, without involving staff.
- **Shadow mode and per-category switches (decision 11)** let the assistant learn from the real conversations without a customer ever seeing an unproven answer, and go live one proven category at a time; Off stays one click away.
- **The brand from the database (decision 9):** the order number has no brand, and the prefill text is customer-editable.

## Not decided yet (before Accepted)

- **The model:** the result of decision 12's comparison test.
- **Grill and stress test** of the whole design, including adversarial prompts (a customer trying to extract cost or supplier names) and the order of checks with ADR-116's existing CS handling (order matcher, status card, STOP/START, opt-in).

## Consequence to track

- PRD §16 item 158 tracks this ADR until it is Accepted and built.
- **2026-12-24:** OpenWA's first 90-day prune. Decision 8's collection must be live before then, or the September–October conversations are lost.
- The handover thresholds (decision 7) should be re-checked against production once there are a few hundred delivered orders.
