"use client";

/**
 * ADR-104 R2 — "Affiliates" is renamed Partners (matching the sidebar
 * group), since it holds resellers too. R15 — sales by channel as three
 * exclusive cards; R16 — the reseller-wallet card splits API vs Bot by
 * `orders.placed_via`. The channel split is not a global filter.
 */

import type { ReactNode } from "react";
import Link from "next/link";
import {
  CHANNEL_LABELS,
  type PlacedVia,
  type ReportChannel,
  getAffiliateBreakdown,
  getChannelBreakdown,
  getResellerBreakdown,
} from "@/lib/reports";
import { Badge, ChangeLine, Pending, ReportCard, ReportTable, previousLabelOf, useReport, type ReportTabProps } from "../ReportKit";
import { formatRm } from "../format";

const CHANNEL_INFO: Record<ReportChannel, { tone: "neutral" | "nude" | "info"; description: string }> = {
  own_brand: { tone: "neutral", description: "Customers who bought on PekanGame or another brand we own." },
  reseller_wallet: { tone: "nude", description: "Partners who pay from a prepaid wallet and resell." },
  external_affiliate: { tone: "info", description: "Customers on an affiliate's own storefront we don't own." },
};

const PLACED_VIA_LABELS: Partial<Record<PlacedVia, string>> = { reseller_bot: "WhatsApp bot", reseller_api: "API" };

function ChannelCard({ badge, tone, value, meta, description, footer, muted }: { badge: string; tone: "neutral" | "nude" | "info"; value: string; meta: string; description: string; footer?: ReactNode; muted: boolean }) {
  return (
    <div className="flex min-w-0 flex-col rounded-lg border border-border bg-surface p-4 shadow-xs">
      <div className="flex items-center justify-between gap-2">
        <Badge tone={tone}>{badge}</Badge>
        <span className="text-theme-xs tabular-nums text-ink-muted">{meta}</span>
      </div>
      <p className={`mt-2 text-[26px] font-semibold leading-8 tabular-nums tracking-tight ${muted ? "text-ink-muted" : "text-ink"}`}>{value}</p>
      {footer}
      <p className="mt-3 border-t border-border pt-3 text-theme-xs text-ink-muted">{description}</p>
    </div>
  );
}

export function PartnersTab(props: ReportTabProps) {
  const { token, filters, compare } = props;
  const channels = useReport(props, () => getChannelBreakdown(token, filters, compare));
  const affiliates = useReport(props, () => getAffiliateBreakdown(token, filters));
  const resellers = useReport(props, () => getResellerBreakdown(token, filters));

  const c = channels.data;
  const total = c?.channels.reduce((sum, ch) => sum + ch.sales, 0) ?? 0;
  const cmp = c?.compare ?? undefined;
  const previousLabel = previousLabelOf(cmp);

  return (
    <div className="flex flex-col gap-6">
      {c ? (
        <div>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
            {c.channels.map((ch) => (
              <ChannelCard
                key={ch.channel}
                badge={CHANNEL_LABELS[ch.channel]}
                tone={CHANNEL_INFO[ch.channel].tone}
                value={formatRm(ch.sales)}
                meta={`${ch.orders_count} ${ch.orders_count === 1 ? "order" : "orders"} · ${(total > 0 ? (ch.sales / total) * 100 : 0).toFixed(1)}%`}
                description={CHANNEL_INFO[ch.channel].description}
                muted={ch.orders_count === 0}
                footer={
                  <>
                    {cmp && <ChangeLine change={cmp.changes[ch.channel]} previousLabel={previousLabel} />}
                    {ch.channel === "reseller_wallet" && c.reseller_wallet_by_placed_via.length > 0 && (
                      <p className="mt-1 text-theme-xs text-ink-muted">
                        {c.reseller_wallet_by_placed_via
                          .map((v) => `${PLACED_VIA_LABELS[v.placed_via] ?? v.placed_via}: ${formatRm(v.sales)} · ${v.orders_count} ${v.orders_count === 1 ? "order" : "orders"}`)
                          .join("  ·  ")}
                      </p>
                    )}
                  </>
                }
              />
            ))}
          </div>
          <p className="mt-2 text-theme-xs text-ink-muted">
            Reseller wallet is checked first. Own brand vs external follows each affiliate&apos;s current setting, so changing it moves past orders too.
          </p>
        </div>
      ) : (
        <Pending error={channels.error} />
      )}

      <ReportCard flush title="Affiliate performance" subtitle="Grouped by the brand on each order. Reseller wallet orders also appear here, under the primary brand.">
        {affiliates.data ? (
          <ReportTable
            rows={affiliates.data.affiliates}
            rowKey={(r) => r.affiliate_id ?? "unknown"}
            columns={[
              { key: "affiliate", header: "Affiliate", render: (r) => <span className="font-medium">{r.affiliate_name}</span> },
              { key: "sales", header: "Sales", align: "right", render: (r) => <span className="font-semibold">{formatRm(r.sales)}</span> },
              { key: "orders", header: "Orders", align: "right", render: (r) => r.orders_count },
              { key: "owner", header: "Owner profit", align: "right", render: (r) => formatRm(r.platform_profit) },
              { key: "affiliate_profit", header: "Affiliate profit", align: "right", render: (r) => <span className={r.affiliate_profit === 0 ? "text-ink-muted" : ""}>{formatRm(r.affiliate_profit)}</span> },
              { key: "avg", header: "Avg order", align: "right", render: (r) => formatRm(r.avg_order_value) },
            ]}
          />
        ) : (
          <Pending error={affiliates.error} className="p-6" />
        )}
      </ReportCard>

      <ReportCard
        flush
        title="Reseller performance"
        subtitle="Wallet-paid orders placed by resellers."
        actions={
          <Link href="/admin/resellers" className="text-theme-xs font-medium text-cyan-ink hover:underline">
            Resellers →
          </Link>
        }
      >
        {resellers.data ? (
          <ReportTable
            rows={resellers.data.resellers}
            rowKey={(r) => r.reseller_id}
            empty="No reseller wallet orders in this range yet."
            columns={[
              { key: "reseller", header: "Reseller", render: (r) => <span className="font-medium">{r.reseller_name}</span> },
              { key: "sales", header: "Sales", align: "right", render: (r) => <span className="font-semibold">{formatRm(r.sales)}</span> },
              { key: "orders", header: "Orders", align: "right", render: (r) => r.orders_count },
              { key: "owner", header: "Owner profit", align: "right", render: (r) => formatRm(r.platform_profit) },
              { key: "avg", header: "Avg order", align: "right", render: (r) => formatRm(r.avg_order_value) },
            ]}
          />
        ) : (
          <Pending error={resellers.error} className="p-6" />
        )}
      </ReportCard>
    </div>
  );
}
