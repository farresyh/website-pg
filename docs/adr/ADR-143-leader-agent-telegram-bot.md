# ADR-143: Leader and agents — a reseller runs its own agent network on a Telegram bot we host

> **Standing (2026-11-18):** In force as a proposal only (Status: Proposed); nothing here is decided for build. Gated on the founder confirming real demand with resellers, then a grill and a money code trace. Would extend [ADR-072](./ADR-072-reseller-system-split.md) (a reseller account), [ADR-075](./ADR-075-reseller-bot-channel.md) (the bot channel) and [ADR-139](./ADR-139-reseller-milestone-rebate.md) (rebate volume).

**Status:** Proposed, 2026-11-18. PRD §16 item 194 (group E: a new feature, after the backlog). Discussed with the founder in Malay on 2026-11-18, with a throwaway Telegram prototype the founder tested as leader and agent the same day. Decisions 1–9 are the founder's. Decisions 10–18 are Claude's draft and are open. **Not grilled on purpose:** the founder first asks resellers whether they want this. If they do, the grill and the money code trace (below) come before Accepted.

## Context

**The idea (founder, 2026-11-18).** A reseller who orders through our bot becomes a **leader** and recruits **agents** under them. The leader is to their agents what we are to our resellers: agents have their own balance, top up, order through a bot and see the leader's prices; the leader sets their own markup and settles with their agents themselves. We sell the leader a service; the agents are not our customers.

**Why it fits.** ADR-139's research: the target resellers are WhatsApp agents of big stores, and agents said our price is about the same as their leader's. A leader who moves to us brings the whole downline's volume, and every agent order is a wallet order at the leader's price.

**What exists today (checked in code, 2026-11-18).**
- A reseller may own several bot groups. `whatsapp_groups` is polymorphic (`owner_type`, `owner_id`); only a supplier is limited to one group (`WhatsAppGroupService`). On production each of the 3 resellers owns 1 group.
- The bot knows the group, not the person. `ResellerBotService::handle()` receives only the group ID; membership is the trust boundary (ADR-075 decision 3).
- Every bot price is the reseller's own wallet price (`OrderPricingResolver::resolveResellerWallet()`), and `.baki` shows the reseller's balance.
- So a leader could add agents to their group today, but the agents would see the leader's cost price and balance, could spend the leader's whole balance with no limit, and the leader would have no per-agent record.
- The partner portal separates account types by `owner_type` (`reseller/src/lib/portal-nav.ts`), and `EnsureAccountType` enforces it on every endpoint. An Affiliate never reaches a Reseller screen or API.
- Orders carry `PlacedVia` (`storefront`, `reseller_api`, `reseller_bot`, `sandbox`).

**CHIP (recorded 2026-11-05, PRD §14).** Collecting payments on behalf of a reseller or affiliate is not supported under our account; PajoStore was allowed only after the founder asked. ADR-131 renamed the reseller wallet "Purchase Balance" because "wallet" suggests regulated e-money.

**Telegram vs WhatsApp (discussed 2026-11-18).** Telegram's Bot API is official and free: a bot is created in BotFather in a minute, every update carries the sender's user ID, private chats are the norm, inline buttons replace typed commands, and a deep link (`t.me/<bot>?start=<code>`) can enrol a user under a leader. One server can run many bots, one token each. Our WhatsApp bot runs on unofficial OpenWA (ban risk, session drops, droplet load: PRD §16 items 4, 59). WhatsApp's advantage is the market: ADR-075's target sellers live on WhatsApp. Whether agents will use Telegram is the open market question (decision 9).

**The prototype (2026-11-18).** A throwaway Node script outside the repo (`@pekangame_test_bot`, fake catalog and money, no production access). The founder tested it as leader and with a second account as agent. Verified to the sen from the bot's state file: invite link and approval; agent top-up by receipt photo with leader Approve/Reject (a rejected top-up credits nothing); agent order debiting the agent at the agent price and the leader at the leader price; a forced failure refunding both; the leader's own order debiting only the leader; per-agent sales and profit; a low-balance warning; markup from buttons; agent order logs routed to a separate Telegram group. What it taught:
- **Markup must be integer sen.** `690 × 1.1` in floating point is `759.0000000000001`, and `ceil` charged RM7.60 for RM7.59. The real build computes it in sen on the server with a stated rounding rule (decision 13).
- **A leader with many agents drowns in order logs** if they all land in the leader's own chat, where the leader also orders. Logs need their own destination (decision 15).
- A settings change that silently unlinked the log group cost a test round; each setting is independent.

## Decision

### Founder decisions (2026-11-18)

1. **Leader ↔ agent mirrors us ↔ reseller.** Agents have their own balance, top up, order and see only the price the leader sets. The leader runs them like we run resellers.
2. **The leader is a Reseller with a "Leader" feature switched on by an admin**, not a new account type.
3. **Agent top-up: the agent pays the leader directly** (bank transfer or the leader's DuitNow QR), sends the receipt in the bot, and the leader approves or rejects with one tap. We never collect agent money. A leader with their own CHIP merchant account paying into the leader's bank, auto-credited by webhook, is a later upgrade (decision 18). Agents paying through our CHIP is out unless CHIP agrees in writing.
4. **The leader keeps topping up their own Purchase Balance with us through CHIP**, as today. It funds every order their agents place.
5. **An agent order debits the agent's balance at the agent price and the leader's Purchase Balance at the leader's price.** A failed order refunds both.
6. **Agents see only the leader's marked-up price**; they never see the leader's price or balance. The leader never sees our tier markup (existing rule).
7. **The leader's web panel lives in the partner portal** as an "Agents" group in the Reseller navigation, shown only to a leader. Affiliates and ordinary resellers see nothing new.
8. **Our books record only our sale to the leader.** The agent's sale, the leader's markup and agent balances are the leader's business. We store them because our software runs that business for the leader, but they never enter our ledger, revenue, profit, accounting, rebate or platform reports. Under IFRS 15 / MPERS we are principal only for the sale to the leader; the leader is principal for the sale to the agent.
9. **Gate:** the founder confirms with resellers that they want this, and that their agents will use Telegram, before any grill or build.

### Draft for the grill (Claude, 2026-11-18)

10. **Channel: Telegram for leaders and agents; the WhatsApp bot stays for ordinary resellers.** Telegram is one more channel into the same order path, with its own `PlacedVia` case, not a new pricing or fulfilment path.
11. **One bot per leader, branded as the leader.** The leader creates it in BotFather and pastes the token into the portal; we host it (one webhook per token). Agents see the leader's brand, not PekanGame, so a leader need not fear us taking their agents. A shared PekanGame bot is the fallback for a leader without one. Leader and agents share the leader's bot; the bot shows each person their own menu by Telegram user ID.
12. **Agents use the bot only** in v1: order, price list, top-up, balance, order history. A web login for agents would be a third portal account type on the auth shared with Affiliates, the riskiest part, so it waits for a real need.
13. **Agent price = leader price × (100 + markup) / 100, rounded up to the sen, in integer arithmetic on the server.** v1 markup: one percentage per leader, chosen from buttons (1–10%). Per-product prices are open.
14. **Agent balances are a separate sub-ledger** owned by the leader: append-only entries (top-up approved, order debit, refund, leader adjustment), never a mutable column (ADR-002's rule, applied to the leader's data). Tables are separate from `ledger_entries`, and none of the platform's report, accounting or `llm_report_*` readers read them.
15. **Notifications:** anything the leader must act on (a new agent, a top-up to approve, a low Purchase Balance, an agent order refused for the leader's balance) always goes to the leader's private chat. Agent order logs go where the leader chooses: nowhere, the private chat, or a log group the leader links by typing `/loggroup` in it.
16. **Leader balance risk.** If the leader's Purchase Balance can't cover an agent order, the order is refused before anything is debited, the agent is told the leader is restocking, and the leader is alerted. The leader is warned early when their balance falls below the sum of agent balances (their stock obligation).
17. **The leader manages agents:** approve or reject a join, suspend, remove, set a per-agent limit (open: daily cap or none), adjust a balance with a reason, and see per-agent sales and profit.
18. **Later, not v1:** the leader's own CHIP account for automatic agent top-ups (decision 3); per-product agent prices; an agent web login.

### Open questions for the grill
- **Service fee:** free, a monthly subscription (like the Affiliate Subscription), or a fee per agent order? A fee is new platform revenue with its own accounting line.
- **Leader deactivated, dormant (ADR-142) or closed (ADR-131):** what happens to agents and their balances? The balances are the leader's obligation, not ours; the leader needs an export, and the leader terms must say so.
- **Leader terms:** the leader alone owes their agents' balances and collects their money; we hold none of it. PDPA: we process agent data (name, Telegram ID, orders) for the leader.
- **Dormancy (ADR-142):** do agent orders count as the leader's transactions? They are wallet orders, so they should; confirm in the code trace.
- **Rebate (ADR-139):** agent orders are leader wallet orders at the leader price, so they count toward the leader's ladder. Confirm nothing double-counts.
- **Disputes:** an agent says a top-up didn't arrive. We hold no agent money; the leader decides, and the bot's record (receipt photo, approval time) is the evidence.
- **Player-ID check:** agents use the same pre-order check as the WhatsApp bot (`.cekid`, ADR-005).

### Money code trace to run before Accepted
Per the repo's money-critical rule: every reader and writer of `orders`, wallet ledger entries and `PlacedVia` (reports, Monthly Summary, accounting, `llm_report_*` views, rebate, dormancy, CS assistant context, admin and portal order screens), to prove that an agent order looks to the platform exactly like a leader's wallet order, and that no agent-side amount reaches a platform reader. Checked per pricing basis (ResellerWallet only, since a leader is a Reseller).

## Rationale

- **Reuses the reseller wallet path unchanged.** From the platform's side an agent order is a leader wallet order, so pricing, fulfilment, refunds, rebate and accounting need no new money rule; the new work is the leader's sub-ledger and the bot.
- **No money of the agent's touches us**, which keeps us inside what CHIP allows and away from e-money (ADR-131).
- **Telegram gives per-person identity, buttons, deep-link enrolment and leader-branded bots at no cost and on an official API**, where WhatsApp would need one group per agent and adds ban risk. The market question is left to the founder's reseller conversations (decision 9).
- **Leader branding protects the leader's network**, the main reason a leader would refuse.

## Consequence to track

- PRD §16 item 194 holds the gate (decision 9). The prototype is throwaway, outside the repo; nothing in it is reused as code.
- If resellers confirm: grill decisions 10–18 and the open questions, run the money code trace, then mark Accepted and plan PRs (Telegram channel and leader sub-ledger first, then the portal Agents group).
- If resellers want it on WhatsApp instead: decisions 10–11 and 15 need re-deciding (one group per agent, no deep links, OpenWA load); decisions 1–8 stand.
