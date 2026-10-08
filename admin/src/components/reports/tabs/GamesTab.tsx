"use client";

import { useState } from "react";
import { getGameBreakdown, getReportSummary } from "@/lib/reports";
import { Input } from "@/components/ui/input";
import { GameIcon, KpiCard, Pending, ReportCard, ReportTable, ShareBar, previousLabelOf, useReport, type ReportTabProps } from "../ReportKit";
import { formatRm } from "../format";

export function GamesTab(props: ReportTabProps) {
  const { token, filters, compare } = props;
  const summary = useReport(props, () => getReportSummary(token, filters, compare));
  const games = useReport(props, () => getGameBreakdown(token, filters));
  const [query, setQuery] = useState("");

  const s = summary.data;
  const cmp = s?.compare ?? undefined;
  const rows = games.data?.games;
  const top = rows?.[0];
  const mostProfitable = rows?.reduce<(typeof rows)[number] | undefined>((best, g) => (g.platform_profit > (best?.platform_profit ?? 0) ? g : best), undefined);
  const visible = rows?.filter((g) => g.game_name.toLowerCase().includes(query.trim().toLowerCase()));
  const filtered = visible !== undefined && rows !== undefined && visible.length !== rows.length;

  return (
    <div>
      <div className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <KpiCard label="Top game" value={top?.game_name ?? "—"} sub={top ? `${formatRm(top.sales)} · ${top.pct_of_sales.toFixed(1)}% of sales` : undefined} />
        <KpiCard label="Most profitable" value={mostProfitable?.game_name ?? "—"} sub={mostProfitable ? `${formatRm(mostProfitable.platform_profit)} owner profit` : rows ? "No owner profit yet" : undefined} />
        <KpiCard label="Games with sales" value={rows ? rows.length.toLocaleString() : "—"} sub="In this period" />
        <KpiCard label="Avg order" value={s ? formatRm(s.avg_order_value) : "—"} sub="Across all games" change={cmp?.changes.avg_order_value} previousLabel={previousLabelOf(cmp)} />
      </div>

      <ReportCard
        flush
        title="Games breakdown"
        subtitle="Only games with paid orders in this period."
        actions={<Input type="search" placeholder="Filter games" aria-label="Filter games" value={query} onChange={(e) => setQuery(e.target.value)} className="h-9 w-48" />}
      >
        {visible && s ? (
          <ReportTable
            rows={visible}
            rowKey={(r) => r.game_id ?? "unknown"}
            empty={filtered ? "No game matches this filter." : undefined}
            columns={[
              {
                key: "game",
                header: "Game",
                render: (r) => (
                  <span className="flex items-center gap-3 font-medium">
                    <GameIcon name={r.game_name} src={r.image_url} />
                    {r.game_name}
                  </span>
                ),
                // The Total row is the whole period's, so it is hidden while a filter narrows the rows.
                total: filtered ? undefined : `Total · ${rows!.length} ${rows!.length === 1 ? "game" : "games"}`,
              },
              { key: "sales", header: "Sales", align: "right", render: (r) => <span className="font-semibold">{formatRm(r.sales)}</span>, total: filtered ? undefined : formatRm(s.total_sales) },
              { key: "share", header: "% of sales", align: "right", render: (r) => <span className="inline-flex items-center gap-2"><ShareBar pct={r.pct_of_sales} />{r.pct_of_sales.toFixed(1)}%</span>, total: filtered ? undefined : "100%" },
              { key: "orders", header: "Orders", align: "right", render: (r) => r.orders_count, total: filtered ? undefined : s.orders_count },
              { key: "owner", header: "Owner profit", align: "right", render: (r) => <span className={r.platform_profit === 0 ? "text-ink-muted" : r.platform_profit < 0 ? "text-danger-ink" : ""}>{formatRm(r.platform_profit)}</span>, total: filtered ? undefined : formatRm(s.platform_profit) },
              { key: "affiliate", header: "Affiliate profit", align: "right", render: (r) => <span className={r.affiliate_profit === 0 ? "text-ink-muted" : ""}>{formatRm(r.affiliate_profit)}</span>, total: filtered ? undefined : formatRm(s.affiliate_profit) },
              { key: "avg", header: "Avg order", align: "right", render: (r) => formatRm(r.avg_order_value), total: filtered ? undefined : formatRm(s.avg_order_value) },
            ]}
          />
        ) : (
          <Pending error={games.error ?? summary.error} className="p-6" />
        )}
      </ReportCard>
    </div>
  );
}
