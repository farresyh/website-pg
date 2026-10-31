import { CardHead, Panel } from "@/components/composites";

/**
 * ADR-133 decision 16: there is no partner sandbox, so a reseller's first
 * order is real money. Three plain rules, no sandbox pretence.
 */
export default function FirstOrderCard() {
  return (
    <Panel>
      <CardHead title="Your first order" description="Orders are real and are paid from your Purchase Balance." />
      <ul className="space-y-2 border-t border-border px-5 py-4 text-sm text-ink-muted">
        <li>
          <strong className="text-ink">Start small.</strong> Pick a low-priced package for your first order.
        </li>
        <li>
          <strong className="text-ink">Check the player ID first.</strong> The documentation shows how to check an ID before you order.
        </li>
        <li>
          <strong className="text-ink">If an order fails,</strong> its amount returns to your Purchase Balance.
        </li>
      </ul>
    </Panel>
  );
}
