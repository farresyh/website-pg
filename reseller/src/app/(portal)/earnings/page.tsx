"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { ApiError } from "@/lib/api-client";
import { getEarnings, type EarningsResponse } from "@/lib/portal";
import { formatRm } from "@/lib/format";
import { BalanceHero, CardHead, ErrorNote, PageHeader, Panel, Skeleton, buttonClass } from "@/components/composites";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { StepsCard } from "@/components/money/MoneyForm";
import LedgerTable from "@/components/money/LedgerTable";

/** The Affiliate's earnings ledger (ADR-059) with the withdrawable balance on top. */
export default function EarningsPage() {
  const [page, setPage] = useState(1);
  const [data, setData] = useState<EarningsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    let cancelled = false;
    getEarnings(session.token, page)
      .then((result) => {
        if (cancelled) return;
        setData(result);
        setError(null);
      })
      .catch((err: unknown) => !cancelled && setError(err instanceof ApiError ? err.message : "Could not load earnings."));
    return () => {
      cancelled = true;
    };
  }, [page]);

  return (
    <div>
      <PageHeader title="Earnings" subtitle="Your margin on each delivered order, less tier fees and withdrawals." />
      {error && <ErrorNote message={error} />}

      <div className="mb-4 grid items-start gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        {data ? (
          <BalanceHero
            icon="trend"
            label="Withdrawable earnings"
            amount={formatRm(data.balance)}
            note="Earnings from an order are added when it is delivered."
            actions={
              <Link href="/withdrawal" className={buttonClass("primary")}>
                <PortalIcon name="bank" className="h-4 w-4" />
                Request withdrawal
              </Link>
            }
          />
        ) : (
          <Skeleton className="h-[200px]" />
        )}
        <StepsCard
          title="Where your earnings come from"
          steps={[
            { title: "Your margin on each sale", body: "Your storefront price minus your wholesale price, on delivered orders only." },
            { title: "Your tier fee", body: "A wholesale tier's monthly fee is taken from your earnings." },
            { title: "Withdrawals", body: "Paid to your bank once PekanGame approves them. Track them under Withdrawal." },
          ]}
        />
      </div>

      <Panel>
        <CardHead title="Earnings history" description="Newest first." divided />
        <LedgerTable entries={data?.entries ?? null} onPage={setPage} empty="No earnings yet. They appear here when your first order is delivered." />
      </Panel>
    </div>
  );
}
