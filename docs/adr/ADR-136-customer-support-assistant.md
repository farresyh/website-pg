# ADR-136: Customer-support assistant on the CS WhatsApp number — answers from approved knowledge, learns only through admin approval, hands order problems to a human

> **Standing (2026-11-03):** Proposed. Extends [ADR-116](./ADR-116-customer-order-notifications-over-whatsapp.md) (the CS session, opt-in, status card, STOP/START) and [ADR-075](./ADR-075-reseller-bot-channel.md) (OpenWA). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Proposed, 2026-11-03. Decisions 1–9 were made with the founder in Malay on 2026-11-03. Still open before Accepted: the model and provider (PDPA, what leaves the platform), how the assistant sits inside the ADR-116 message flow, and a grill plus stress test of the whole design. PRD §16 item 158.

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

## Rationale

- **Approval-gated learning (decision 2)** keeps the "grows over time" goal without letting a single bad reply spread. The study data (decision 8) is tiny today (about 98 incoming personal messages from about 15 chats in 39 days), so an auto-learning model would learn from almost nothing. Approved entries are a corpus that improves with each admin review.
- **Honest identity (decision 5):** a customer who feels deceived is the one who taps Report, and Reports are what ban an unofficial number. Natural tone is what makes it feel human; denying being a bot adds risk and no value.
- **30 minutes (decision 7)** is where the production data turns: 92% of orders arrive within it, and every one past it needed an admin. 15 minutes covers the "baru 5–10 minit" complaints the founder sees, without involving staff.
- **The brand from the database (decision 9):** the order number has no brand, and the prefill text is customer-editable.

## Not decided yet (before Accepted)

- **Model and provider:** which LLM (Gemini is already used for ADR-087), what customer text leaves the platform, data-processing terms, PDPA cross-border transfer, cost per message, and fallback when the provider is down.
- **Placement in the ADR-116 flow:** the order matcher, status card, STOP/START and opt-in run first; when the assistant answers and when it stays silent; how ADR-116 decision 5 ("Contact Support message = no auto-reply") changes; how staff see a handover and take over (and how the assistant stays quiet while a human is in the conversation); rate caps and pacing under OpenWA's anti-ban rules.
- **Knowledge storage and retrieval:** where approved entries live, how they are searched, and how game and FAQ data are kept in sync.
- **Grill and stress test** of the whole design, including adversarial prompts (a customer trying to extract cost or supplier names).

## Consequence to track

- PRD §16 item 158 tracks this ADR until it is Accepted and built.
- **2026-12-24:** OpenWA's first 90-day prune. Decision 8's collection must be live before then, or the September–October conversations are lost.
- The handover thresholds (decision 7) should be re-checked against production once there are a few hundred delivered orders.
