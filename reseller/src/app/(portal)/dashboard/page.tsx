"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { getDashboard, getDomains, listOrders, type DashboardStats, type DomainsResponse, type OrderListItem } from "@/lib/portal";
import {
  getResellerProfile,
  getWallet,
  getWebhook,
  listApiKeys,
  type ResellerApiKeyRow,
  type ResellerProfile,
  type ResellerWebhook,
  type WalletResponse,
} from "@/lib/reseller-portal";
import { dateAndTime, formatRm, greeting } from "@/lib/format";
import { Badge, BalanceHero, CardHead, ErrorNote, Facts, PageHeader, Panel, Skeleton, buttonClass } from "@/components/composites";
import { PortalIcon, type PortalIconName } from "@/components/shell/PortalIcon";
import OrderTable from "@/components/orders/OrderTable";

const RECENT = 5;
const DOCS_URL = "https://docs.pekangame.space";

export default function DashboardPage() {
  const session = useClientSession();
  if (!session) return null;

  const firstName = session.name.trim().split(/\s+/)[0] ?? "";
  const hello = `${greeting(new Date().getHours())}${firstName ? `, ${firstName}` : ""}`;

  return session.owner_type === "reseller" ? <ResellerDashboard hello={hello} /> : <AffiliateDashboard hello={hello} />;
}

/**
 * ADR-128 / ADR-072: no reseller dashboard endpoint exists, so this reads
 * the wallet, profile, API keys, webhook and the newest orders the portal
 * already loads elsewhere. Nothing is summed here.
 */
function ResellerDashboard({ hello }: { hello: string }) {
  const [data, setData] = useState<{
    wallet: WalletResponse;
    profile: ResellerProfile;
    keys: ResellerApiKeyRow[];
    webhook: ResellerWebhook | null;
    orders: OrderListItem[];
  } | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    Promise.all([
      getWallet(session.token),
      getResellerProfile(session.token),
      listApiKeys(session.token),
      getWebhook(session.token),
      listOrders(session.token, "reseller", { per_page: RECENT }),
    ])
      .then(([wallet, profile, keys, webhook, orders]) => setData({ wallet, profile, keys, webhook: webhook.webhook, orders: orders.data }))
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the dashboard."));
  }, []);

  const entries = data?.wallet.entries.data ?? [];
  const lastTopup = entries.find((e) => e.type === "wallet_topup");
  const lastOrder = entries.find((e) => e.type === "wallet_debit");
  const activeKeys = data?.keys.filter((k) => !k.revoked_at) ?? [];
  const lastUsed = activeKeys.map((k) => k.last_used_at).filter((d): d is string => !!d).sort().at(-1) ?? null;

  return (
    <div>
      <PageHeader title={hello} subtitle="Your wallet, your integrations and the latest orders placed through them." />
      {error && <ErrorNote message={error} />}

      <div className="mb-4 grid items-start gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        {data ? (
          <BalanceHero
            icon="wallet"
            label="Wallet balance"
            amount={formatRm(data.wallet.balance)}
            note="Available for API and WhatsApp bot orders right now."
            actions={
              <>
                <Link href="/wallet" className={buttonClass("primary")}>
                  <PortalIcon name="plus" className="h-4 w-4" />
                  Top up wallet
                </Link>
                <Link href="/wallet" className={buttonClass("secondary")}>
                  Wallet history
                </Link>
              </>
            }
          >
            <Facts
              items={[
                ...(lastTopup ? [{ label: "Last top-up", value: formatRm(lastTopup.amount), sub: dateAndTime(lastTopup.created_at).date }] : []),
                ...(lastOrder ? [{ label: "Last order", value: formatRm(Math.abs(lastOrder.amount)), sub: whenLabel(lastOrder.created_at) }] : []),
                { label: "Pricing tier", value: data.profile.tier_name ?? "—", sub: "Set by PekanGame" },
              ]}
            />
          </BalanceHero>
        ) : (
          <Skeleton className="h-[260px]" />
        )}

        {data ? (
          <Panel>
            <CardHead title="How your orders reach us" />
            <ChannelRow
              icon="code"
              title="REST API"
              detail={
                activeKeys.length === 0
                  ? "No active key yet"
                  : `${activeKeys.length} active key${activeKeys.length === 1 ? "" : "s"}${lastUsed ? ` · last used ${dateAndTime(lastUsed).date}` : " · not used yet"}`
              }
              end={activeKeys.length ? <Badge tone="success">Active</Badge> : <Badge tone="neutral">Not set up</Badge>}
            />
            <ChannelRow
              icon="refresh"
              title="Delivery webhook"
              detail={data.webhook?.url ?? "Not set — you'll need to poll order status"}
              end={data.webhook ? <Badge tone={data.webhook.is_active ? "success" : "neutral"}>{data.webhook.is_active ? "Active" : "Paused"}</Badge> : <Badge tone="neutral">Not set up</Badge>}
            />
            <ChannelRow
              icon="doc"
              title="API documentation"
              detail="docs.pekangame.space"
              end={
                <a href={DOCS_URL} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-[13px] font-semibold text-cyan-ink hover:underline">
                  Open <PortalIcon name="external" className="h-3.5 w-3.5" />
                </a>
              }
            />
            <div className="border-t border-border px-5 py-3">
              <Link href="/api-keys" className="inline-flex items-center gap-1 text-[13px] font-semibold text-cyan-ink hover:underline">
                Manage API keys <PortalIcon name="arrowRight" className="h-3.5 w-3.5" />
              </Link>
            </div>
          </Panel>
        ) : (
          <Skeleton className="h-[260px]" />
        )}
      </div>

      <RecentOrders orders={data?.orders ?? null} ownerType="reseller" description={`The ${RECENT} newest orders from your API and bot. This is not a full list of problems.`} />
    </div>
  );
}

function AffiliateDashboard({ hello }: { hello: string }) {
  const [stats, setStats] = useState<DashboardStats | null>(null);
  const [orders, setOrders] = useState<OrderListItem[] | null>(null);
  const [domains, setDomains] = useState<DomainsResponse | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const session = getClientSession();
    if (!session) return;
    Promise.all([getDashboard(session.token), listOrders(session.token, "affiliate", { per_page: RECENT }), getDomains(session.token)])
      .then(([dashboard, recent, domainList]) => {
        setStats(dashboard);
        setOrders(recent.data);
        setDomains(domainList);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load the dashboard."));
  }, []);

  const primary = domains?.domains.find((d) => d.is_primary) ?? domains?.domains[0] ?? null;
  const sub = stats?.subscription ?? null;
  const tierTone = sub?.status === "active" ? "success" : sub?.status === "grace" ? "warning" : "danger";

  return (
    <div>
      <PageHeader title={hello} subtitle={primary ? `Your earnings and how ${primary.hostname} is selling.` : "Your earnings and how your storefront is selling."} />
      {error && <ErrorNote message={error} />}

      <div className="mb-4 grid items-start gap-4 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
        <div className="flex flex-col gap-4">
          {stats ? (
            <BalanceHero
              icon="trend"
              label="Withdrawable earnings"
              amount={formatRm(stats.earnings_balance)}
              note="Your margin from delivered orders, ready to send to your bank."
              actions={
                <>
                  <Link href="/withdrawal" className={buttonClass("primary")}>
                    <PortalIcon name="bank" className="h-4 w-4" />
                    Request withdrawal
                  </Link>
                  <Link href="/earnings" className={buttonClass("secondary")}>
                    Earnings history
                  </Link>
                </>
              }
            />
          ) : (
            <Skeleton className="h-[200px]" />
          )}
          {domains && (
            <Panel>
              <CardHead title="Your storefront" />
              <ChannelRow
                icon="globe"
                title={primary?.hostname ?? "No domain yet"}
                detail={primary ? (primary.is_primary ? "Primary domain" : "Domain") : "Connect your own domain to start selling"}
                end={primary ? <Badge tone={primary.status === "active" ? "success" : primary.status === "pending" ? "warning" : "danger"}>{primary.status === "active" ? "Live" : primary.status === "pending" ? "Checking DNS" : primary.status === "failed" ? "Failed" : "Suspended"}</Badge> : <Link href="/domains" className="text-[13px] font-semibold text-cyan-ink hover:underline">Add</Link>}
              />
              <ChannelRow
                icon="store"
                title="Branding"
                detail="Logo, colours and footer"
                end={<Link href="/storefront" className="text-[13px] font-semibold text-cyan-ink hover:underline">Edit</Link>}
              />
              {primary?.status === "active" && (
                <div className="border-t border-border px-5 py-3">
                  <a href={`https://${primary.hostname}`} target="_blank" rel="noreferrer" className={buttonClass("secondary", true)}>
                    <PortalIcon name="external" className="h-3.5 w-3.5" />
                    Open storefront
                  </a>
                </div>
              )}
            </Panel>
          )}
        </div>

        <div className="flex flex-col gap-4">
          {stats ? (
            <div className="grid grid-cols-2 overflow-hidden rounded-lg border border-border bg-surface shadow-xs">
              <SalesFigure label="Sales today" amount={formatRm(stats.today.sales)} orders={stats.today.orders} />
              <SalesFigure label="Sales this month" amount={formatRm(stats.this_month.sales)} orders={stats.this_month.orders} divider />
            </div>
          ) : (
            <Skeleton className="h-[120px]" />
          )}
          {stats && (
            <Panel>
              <div className="px-5 py-4">
                <div className="flex items-start justify-between gap-2">
                  <div>
                    <p className="text-[13px] font-medium text-ink-muted">Wholesale tier</p>
                    <p className="text-xl font-[650] leading-7 text-ink">{sub?.tier_name ?? "None"}</p>
                  </div>
                  {sub && <Badge tone={tierTone}>{sub.status === "active" ? "Active" : sub.status === "grace" ? "Grace period" : "Lapsed"}</Badge>}
                </div>
                {sub ? (
                  <Facts
                    columns={2}
                    items={[
                      { label: "Monthly fee", value: formatRm(sub.monthly_fee_sen), sub: "From your earnings" },
                      sub.status === "grace" && sub.grace_until
                        ? { label: "Grace ends", value: dateAndTime(sub.grace_until).date, sub: dateAndTime(sub.grace_until).time }
                        : { label: "Next charge", value: dateAndTime(sub.next_charge_at).date, sub: dateAndTime(sub.next_charge_at).time },
                    ]}
                  />
                ) : (
                  <p className="mt-2 text-[13px] text-ink-muted">You buy at the standard price. A wholesale tier lowers it.</p>
                )}
                <Link href="/subscription" className="mt-4 inline-flex items-center gap-1 text-[13px] font-semibold text-cyan-ink hover:underline">
                  Subscription details <PortalIcon name="arrowRight" className="h-3.5 w-3.5" />
                </Link>
              </div>
            </Panel>
          )}
        </div>
      </div>

      <RecentOrders orders={orders} ownerType="affiliate" description={`The ${RECENT} newest orders on your storefront.`} />
    </div>
  );
}

function SalesFigure({ label, amount, orders, divider = false }: { label: string; amount: string; orders: number; divider?: boolean }) {
  return (
    <div className={`px-5 py-4 ${divider ? "border-l border-border" : ""}`}>
      <p className="text-[13px] font-medium text-ink-muted">{label}</p>
      <p className="mt-1.5 text-2xl font-[650] leading-8 tracking-[-0.02em] text-ink">{amount}</p>
      <p className="mt-1 text-xs text-ink-subtle">
        {orders} paid order{orders === 1 ? "" : "s"}
      </p>
    </div>
  );
}

function ChannelRow({ icon, title, detail, end }: { icon: PortalIconName; title: string; detail: string; end: React.ReactNode }) {
  return (
    <div className="flex items-center gap-3 border-t border-border px-5 py-3 first-of-type:border-t-0">
      <span className="grid h-9 w-9 shrink-0 place-items-center rounded-md bg-subtle text-ink-muted">
        <PortalIcon name={icon} />
      </span>
      <span className="flex min-w-0 flex-1 flex-col">
        <span className="truncate text-sm font-semibold text-ink">{title}</span>
        <span className="truncate text-xs text-ink-subtle">{detail}</span>
      </span>
      {end}
    </div>
  );
}

function RecentOrders({ orders, ownerType, description }: { orders: OrderListItem[] | null; ownerType: "affiliate" | "reseller"; description: string }) {
  return (
    <Panel>
      <CardHead
        title="Recent orders"
        description={description}
        action={
          <Link href="/orders" className={buttonClass("secondary", true)}>
            All orders <PortalIcon name="arrowRight" className="h-3.5 w-3.5" />
          </Link>
        }
      />
      {orders === null ? (
        <div className="space-y-2 px-5 pb-5">
          {Array.from({ length: 3 }, (_, i) => (
            <Skeleton key={i} className="h-12" />
          ))}
        </div>
      ) : orders.length === 0 ? (
        <p className="border-t border-border p-6 text-center text-sm text-ink-subtle">No orders yet.</p>
      ) : (
        <div className="border-t border-border md:border-t-0">
          <OrderTable orders={orders} ownerType={ownerType} />
        </div>
      )}
    </Panel>
  );
}

/** "Today, 4:12 PM" for today, else the date. */
function whenLabel(iso: string | null): string {
  if (!iso) return "—";
  const { date, time } = dateAndTime(iso);
  return date === dateAndTime(new Date().toISOString()).date ? `Today, ${time}` : date;
}
