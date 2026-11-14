# ADR-141: Night Market — a weekly, per-customer set of discounted package cards that gives customers a reason to come back

> **Standing (2026-11-14):** In force as a proposal only (Status: Proposed); nothing here is decided for build until the grill and the money code trace below. Would extend [ADR-027](./ADR-027-vip-membership.md) (member pricing), [ADR-056](./ADR-056-reseller-wholesale-pricing-subscription-tiers.md) (affiliate wholesale pricing) and [ADR-118](./ADR-118-marketing-campaigns.md) (campaign budget, no stacking on Member). Build and live status: [`prd.md` §15](../prd.md). The text below is a dated record; a later addendum in this file overrides earlier text, including the **Status** line.

**Status:** Proposed, 2026-11-14. PRD §16 item 187 (group E: a new feature, after the backlog). Discussed with the founder in Malay on 2026-11-14. Decisions 1–4 are the founder's. Decisions 5–20 are Claude's draft and are open. The founder chose to record the idea now and build it only after PRD §16 groups A and B are cleared (decision 1 of the founder's 2026-10-08 rule). Before Accepted it needs AGENTS.md lifecycle step 2 in full: the grill, then the money code trace (the checklist under Consequences), then a simulation through the real pricing seam on production orders.

## Context

**Where the idea came from.** Two references the founder shared:
1. A short on Panera's coffee subscription. The video claims subscribers visited 8× as often as the average customer and about 30% also bought food. Its lesson: the cheapest product doesn't have to make money; its job is to bring the customer back, and the profit comes from other products. (The 8× and 30% are the video's claims, not checked.)
2. A video explaining VALORANT's Night Market: six hidden cards per player, each a random discounted skin (10–49% off). The top-tier skins never appear, each player gets at least two premium cards, and no weapon appears twice. It runs once per Act (roughly every two months) for 12 days to over 4 weeks, announced about a week ahead. Players wait for it because it is rare and predictable, and they share what they got. The video also says it has gone stale: purely random cards disappoint ("a skin for a gun I never use"). The fixes players ask for are a reroll, a wishlist, and cards based on what the player actually uses. How Riot picks each player's cards is not public.

**The business goal (founder, 2026-11-14).** For the next two to three months the priority is a large customer base. Breaking even is acceptable; profit can wait.

**Production, read-only, 2026-11-14** (delivered storefront orders, reseller wallet excluded):
- 201 distinct customer emails since September; **138 (69%) bought once**. November so far: 301 orders from 193 customers.
- Repeat buying is mostly same-day: of 119 repeat gaps since 2026-10-05, 79 were under a day, 36 were 1–8 days and 4 were over 8 days. The data covers six weeks, so a monthly rhythm can't be seen yet.
- The "coffee and food" shape exists. Orders under RM10 are 43% of orders and 6% of profit. Orders of RM60 or more are 14% of orders and 64% of profit.
- Best sellers: PUBG 325 UC, MLBB MY 112 Diamonds, 14 Diamonds, First Topup 50+50. The Weekly Diamond Pass is bought again and again by the same customers (MY: 9 orders from 3 customers).
- Busiest hour is 16:00 MYT (MLBB events usually drop at 4 pm on Friday). There is a second peak from 19:00 to 23:00. Friday has about twice the orders of any other day.
- Brands: OhahaStore 125 orders, **PajoStore (third-party) 125**, PekanGame 70. Half the storefront volume is on a brand with no consumer Membership (ADR-061).
- Membership: Tier 1 RM6.90 (quota RM335, 50% off the markup), Tier 2 RM19.90 (quota RM605, 70% off the markup). 4 active members, 6 member orders in total.
- Package markup is 15% almost everywhere. PajoStore prices at wholesale tier 8% on cost plus its own 6% markup.

**How price works today** (`OrderPricingResolver`, `PricingService`, `MembershipPricingService`):
- Guest on an owned brand: `cost × (1 + markup%)`.
- Member: `cost × (1 + markup% × (1 − discount%))`. Member price overrides every other basis, and `affiliate_profit` is 0. The quota is debited at the member price; if it doesn't cover the order, the order falls back to the normal price without blocking checkout.
- Third-party affiliate: `cost × (1 + tier%) × (1 + affiliate markup%)`. Platform profit is the tier part; the affiliate's is its markup.
- A campaign promo code applies only to the Standard price, never to Member (CAMP-3). So the codebase already refuses to stack discounts.

## Decision

### Founder decisions (2026-11-14)

1. **Cards are unique per customer.** No two customers are meant to see the same set.
2. **Everyone can see the Night Market**, not only members.
3. **Third-party affiliate brands should be able to run it too.** How the discount is shared is decision 15, still open.
4. **Weekly, on Friday**, rather than every two days. The founder leaned to Friday and asked about a two-day cycle. The recommendation was weekly (decision 6), and the grill confirms it.

### Draft for the grill (Claude, 2026-11-14)

**When**

5. **It opens Friday 20:00 MYT and closes Sunday 23:59 MYT.** Not at 16:00. At 16:00 on an MLBB event Friday, customers already arrive to buy at full price, so a discount then would only give away margin on orders we would get anyway. Instead, a banner during the 16:00 rush says "Night Market opens at 8 pm tonight", so the same visitor comes back a second time. 20:00 sits inside the evening peak, and three nights give customers who aren't paid on Friday a chance too.
6. **Weekly, not more often.** Rarity is what makes customers wait. VALORANT's runs every two months and still went stale. A discount that is almost always on teaches customers never to pay full price, and pulling it back later feels like a price rise. The repeat data and the Weekly Pass already follow a weekly rhythm. At about 200 customers a month, two schedules can't be A/B tested, so pick one and measure (decision 20). Reasons to come back between Fridays belong to member pricing on small packs (decision 19), not to a more frequent Night Market.

**What each customer gets**

7. **Identity.** On an owned brand, the member's email (OTP, ADR-027). On a third-party brand, which has no membership, a verified phone or email. How it is verified is open.
8. **Cards are deterministic per identity and week.** A seed from the identity and the ISO week picks the cards, so reloading shows the same cards. New cards come only with the next Friday.
9. **The card pool comes from what the customer actually buys:** the games they bought, packs around their usual size, and one size above it to nudge a bigger order. A customer with no history picks a game first (VALORANT's wishlist idea), and the cards come from that game's best sellers.
10. **Guards on the pool:** at least one card is a big pack (the RM60+ band that carries the profit); no package twice; packages whose markup is too small for the discount to mean anything are left out (14 Diamonds makes RM0.11); combo packages and joki are out unless the code trace shows they are safe.
11. **The discount is a cut of the markup, never of the price**, the same shape as member pricing. The price can't go below cost by construction. Each card draws its cut at random from a range the admin sets in the panel (for example 60–90% of the markup). The customer sees ringgit saved ("Jimat RM5.38"), not a percentage, as campaigns already do (CAMP-3).
12. **Reroll.** Possibly one free reroll a week. **Never a paid reroll:** paying for a random chance is close to gambling (Malaysian law, CHIP's terms).

**Who can buy, and at what price**

13. **On an owned brand, everyone sees their cards, but only a member can buy.** The Night Market becomes the door into Membership. This one is still open: the founder leaned to "for everyone" for viewing; whether buying is member-only goes to the grill.
14. **Discounts never stack.** A member pays the lower of the member price and the card's price, never both. A card is worth showing only if its cut is deeper than the member's tier (Tier 1 50%, Tier 2 70%). A promo code is refused on a Night Market price, as it already is on Member (CAMP-3). Store credit (a voucher) is payment, not a discount, so it is still allowed.
15. **On a third-party brand, the cut is shared in proportion.** The same markup-cut percentage applies to our tier markup and to the affiliate's own markup. Worked example: 112 Diamonds, cost about RM7.61, a 50% cut. PajoStore's price goes from RM8.71 to RM8.15: our share falls from RM0.61 to RM0.30 and PajoStore's from RM0.49 to RM0.24. The affiliate opts in from the portal, because it cuts their earnings. The portal shows only "Night Market cuts your markup by X%", never our tier percentage (AGENTS.md: platform cost structure is private). Considered and not preferred: we fund the whole cut (most of our margin on another brand's sale), or the affiliate funds it alone (they won't join).
16. **Limits.** Each card can be bought once per identity per week, in place of the member quota. Whether a member's Night Market order also debits the member quota is open. A third-party brand, where anyone can get an identity, also has a weekly ringgit budget using ADR-118's campaign budget mechanism. When it runs out, the cards show as sold out.

**Spreading the word and measuring**

17. **Announce it ahead.** VALORANT's is announced about a week early. For us: PekanGame's TikTok and Instagram (the `pekangame-video` skill), email to members, and WhatsApp only to customers who opted in (ADR-116: no cold reachout).
18. **The reveal is part of the product.** Cards flip one by one, and a result can be shared to social media.
19. **The "coffee" is separate and stays member pricing:** the Weekly Pass and small packs near cost for members. This proposal doesn't change member pricing; any change there is its own ADR-027 addendum.
20. **The first four to six weeks are an experiment.** Measured weekly: customers who came back during the Night Market, members who joined from it, total discount given against the extra profit it brought, and the share of Night Market orders that would have been bought at full price anyway. Whether to change the schedule is decided only after that.

## Rationale

- **The problem it targets is real and measured:** 69% of customers buy once. The Panera lesson is about visits, and the Night Market gives a weekly reason to visit.
- **It sells the "food".** The deepest card is always a big pack, where 64% of our profit is.
- **Rare and predictable beats frequent.** That is what makes customers wait for VALORANT's, and frequency is what wore it down.
- **Cards from purchase history fix what went stale in VALORANT's.** We have the history and Riot's design ignores it.
- **A cut of the markup reuses the member pricing shape**, so it can't go below cost, and one rule explains it on every brand.
- **No stacking follows the codebase's existing stance** (CAMP-3, member price overrides).

## Consequence to track

- **Money code trace before Accepted** (AGENTS.md lifecycle step 2). Every writer and reader of:
  - `selling_price`, `platform_profit`, `affiliate_profit` and `pricing_basis` (a new basis, or Member/Standard with a flag?);
  - every recompute path: resend (ADR-105, which freezes the member markup), combo residual (ADR-107), real cost at delivery (ADR-111), partial delivery (ADR-094);
  - the affiliate ledger split (ADR-060), the member quota debit, the ADR-118 campaign budget and margin floor;
  - reports, the Monthly Summary (does a Night Market discount get its own line, like "KOL promo discounts"?), analytics, and the affiliate portal.

  Each margin claim is checked per pricing basis: Standard, Member, Affiliate tier, Affiliate-lapsed.
- **Simulation:** the weekly discount bill at November's volume through the real `OrderPricingResolver`, against the founder's break-even budget (profit since October is about RM1,000).
- **Guest checkout (ADR-011) is unchanged.** Identity is the member's email or a verified contact for this feature only, not a customer account.
- **A sore point to accept knowingly:** a customer may see a friend get the same pack cheaper. The page has to say that every customer gets different cards.
- **Load:** a Friday-evening burst on the card endpoint. ADR-014 and ADR-077 caching apply; cards are computed once per identity and week and then cached.
