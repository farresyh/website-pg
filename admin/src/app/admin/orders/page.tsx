"use client";

/**
 * ORD-1..7 — list with ORD-2's status filters + search (ORD-1), a
 * detail view (ORD-6: customer info, game/package, payment info,
 * supplier response), and a single "Resend Delivery" action (ADR-017)
 * that folds ORD-7's original plain retry into the same flow — the
 * package picker defaults to the order's own package (a same-package
 * resend behaves like the old "Retry Delivery" button did), with the
 * option to swap to a different same-game package. The plain
 * `POST /api/orders/{order}/retry-delivery` endpoint (ADR-014) still
 * exists on the backend, just no longer has its own separate button
 * here — two buttons for "try to fix a failed delivery" was more
 * confusing than useful (founder feedback, docs/prd.md §14). "Issue
 * Voucher" (ORD-7's other resolution path, ADR-004) sits alongside
 * Resend Delivery, hidden once `order.voucher` is already set (at
 * most one per order). ADR-026 (ORD-10): a `needs_review` order gets
 * the same Resend Delivery action plus a new "Mark as Delivered…" —
 * "Issue Voucher" is deliberately never shown for this state (decision
 * 4c), the one exit this feature does not open. Export (ORD-5) remains
 * a later pass.
 */

import { Suspense, useEffect, useMemo, useRef, useState, type ReactNode } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import {
  DataTable,
  DataTableTableContainer,
  DataTableTable,
  DataTableTHead,
  DataTableTHeadRow,
  DataTableTHeadCell,
  DataTableTBody,
  DataTableRow,
  DataTableCell,
} from "@/components/ui/datatable";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectTrigger,
  SelectValue,
  SelectIndicator,
  SelectPortal,
  SelectPositioner,
  SelectPopup,
  SelectList,
  SelectOption,
} from "@/components/ui/select";
import { Popover, PopoverPortal, PopoverPositioner, PopoverPopup } from "@/components/ui/popover";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type OrderListItem,
  type OrderDetail,
  type OrderPage,
  type OrderStatusFilter,
  type OrderSummary,
  type IssueVoucherResult,
  type OrderSourceFilter,
  listOrders,
  getOrder,
  getOrderSummary,
  refundOrderToWallet,
  retryOrderDelivery,
  exportOrders,
} from "@/lib/orders";
import { type Game, listGames } from "@/lib/games";
import { DATE_RANGE_PRESETS, type DateRangePreset, resolveDateRange } from "@/lib/date-range";
import ResendDeliveryModal from "@/components/orders/ResendDeliveryModal";
import IssueVoucherModal, { isRestoreOnly } from "@/components/orders/IssueVoucherModal";
import MarkDeliveredModal from "@/components/orders/MarkDeliveredModal";
import ConfirmFailedModal from "@/components/orders/ConfirmFailedModal";
import NeedsReviewBanner from "@/components/orders/NeedsReviewBanner";
import RefundInformationCards from "@/components/orders/RefundInformationCards";
import OrderDetailCards from "@/components/orders/OrderDetailCards";
import OrderSummaryCards from "@/components/orders/OrderSummaryCards";
import DeliveryLogsTable from "@/components/orders/DeliveryLogsTable";
import ComboLegBreakdown from "@/components/orders/ComboLegBreakdown";
import ManualCheckButtons from "@/components/orders/ManualCheckButtons";

// ADR-092: cards poll on this interval while the page is open — the one
// piece of "proactive" behaviour kept from the dropped WhatsApp-alert
// idea, cheap because it's a single grouped-count query, not the full
// paginated list.
const SUMMARY_POLL_MS = 60_000;

const STATUS_FILTERS: { value: OrderStatusFilter; label: string }[] = [
  { value: "all", label: "All" },
  { value: "need_action", label: "Need Action" },
  { value: "needs_review", label: "Needs Review" },
  { value: "pending_delivery", label: "Pending (Supplier)" },
  { value: "processing", label: "Processing" },
  { value: "completed", label: "Completed" },
  { value: "awaiting_payment", label: "Awaiting Payment" },
  { value: "today", label: "Today" },
];

/** ADR-108 decision 5 — Source filter, single-select, mirrors the Source column's own 3 states. */
const SOURCE_FILTERS: { value: OrderSourceFilter | ""; label: string }[] = [
  { value: "", label: "All sources" },
  { value: "direct", label: "Direct" },
  { value: "affiliate", label: "Affiliate" },
  { value: "reseller", label: "Reseller" },
];

/** ADR-108 decision 8 — every column hideable except Order # and Actions. */
const TOGGLEABLE_COLUMNS = [
  { key: "customer", label: "Customer" },
  { key: "game", label: "Game / Package" },
  { key: "source", label: "Source" },
  { key: "amount", label: "Final Amount" },
  { key: "payment", label: "Payment" },
  { key: "delivery", label: "Delivery" },
  { key: "date", label: "Date" },
] as const;
type ToggleableColumnKey = (typeof TOGGLEABLE_COLUMNS)[number]["key"];
const COLUMNS_STORAGE_KEY = "pekangame-admin-orders-columns";

function loadHiddenColumns(): Set<ToggleableColumnKey> {
  try {
    const raw = localStorage.getItem(COLUMNS_STORAGE_KEY);
    return raw ? new Set(JSON.parse(raw)) : new Set();
  } catch {
    return new Set();
  }
}

function formatRm(sen: number): string {
  return `RM ${(sen / 100).toFixed(2)}`;
}

const paymentStatusSeverity: Record<OrderListItem["payment_status"], "warn" | "success" | "danger"> = {
  pending: "warn",
  paid: "success",
  failed: "danger",
};

const deliveryStatusSeverity: Record<OrderListItem["delivery_status"], "secondary" | "warn" | "success" | "danger" | "info" | "review"> = {
  not_started: "secondary",
  processing: "warn",
  delivered: "success",
  failed: "danger",
  // ADR-104 decision 3: the artifact's own distinct "review" token, not
  // "warn" — closes the exact ADR-032 gap the comment below already
  // flagged (needs_review and processing used to share one color).
  needs_review: "review",
  // ADR-032 — a distinct color from "processing" so an admin can tell
  // at a glance this is waiting on an async supplier, not a normal
  // in-flight delivery attempt.
  pending: "info",
};

/**
 * ADR-108 decision 2 — at most one compensation Tag per row now,
 * replacing the old up-to-4-pill stack (a real order on prod showed
 * Voucher Paid + Voucher Issued + Restored simultaneously). Priority,
 * most-final-state wins: Restored > Wallet Refunded > Voucher Issued >
 * Voucher Paid (the least informative — just the payment method, not a
 * compensation outcome). Order Detail's own RefundInformationCards
 * still shows every independent fact in full; this only declutters the
 * list row.
 */
function primaryCompensationTag(
  order: Pick<OrderListItem, "has_voucher_restored" | "has_wallet_refund" | "has_compensation_voucher" | "has_used_voucher">,
): { label: string; severity: "secondary" | "warn" | "success" | "info" } | null {
  if (order.has_voucher_restored) return { label: "Restored", severity: "success" };
  if (order.has_wallet_refund) return { label: "Wallet Refunded", severity: "info" };
  if (order.has_compensation_voucher) return { label: "Voucher Issued", severity: "warn" };
  if (order.has_used_voucher) return { label: "Voucher Paid", severity: "secondary" };
  return null;
}

// ADR-104: payment/delivery status specifically render as a dot + plain
// text (no pill background), matching the artifact's own OrderDetailFailed/
// OrdersPage mockups — every other Tag usage on this page (compensation
// badges, pricing-basis badges, combo-leg/outcome tags) keeps the shared
// `Tag` component's pill styling untouched. Same severity values already
// computed above, just a different renderer — no status/label/data change.
const STATUS_TEXT_COLOR: Record<string, string> = {
  default: "text-cyan-ink",
  secondary: "text-neutral-ink",
  info: "text-info-ink",
  success: "text-success-ink",
  warn: "text-warning-ink",
  danger: "text-danger-ink",
  review: "text-review-ink",
};

function StatusText({ severity, children }: { severity: string; children: ReactNode }) {
  const color = STATUS_TEXT_COLOR[severity] ?? "text-ink-muted";
  return (
    <span className={`inline-flex items-center gap-1.5 text-sm font-medium ${color}`}>
      <span aria-hidden="true" className="h-1.5 w-1.5 shrink-0 rounded-full bg-current" />
      {children}
    </span>
  );
}

export default function OrdersPage() {
  return (
    <Suspense fallback={<p className="text-sm text-ink-muted">Loading…</p>}>
      <OrdersPageInner />
    </Suspense>
  );
}

/** Wrapped in Suspense above — useSearchParams() (ORD-6 deep-link from Customer Analytics) requires it. */
function OrdersPageInner() {
  const router = useRouter();
  const searchParams = useSearchParams();
  // Read in an effect, not render body — see UserDropdown.tsx for why.
  const session = useClientSession();

  const [status, setStatus] = useState<OrderStatusFilter>("all");
  const [search, setSearch] = useState("");
  const [pageNumber, setPageNumber] = useState(1);
  // ADR-108 decisions 5-7 — Source/Game/date-range, each orthogonal to
  // the status tab above (can combine with any of them, same as the
  // Reports page's own filter row).
  const [source, setSource] = useState<OrderSourceFilter | "">("");
  const [gameId, setGameId] = useState<number | "">("");
  const [games, setGames] = useState<Game[]>([]);
  const [rangePreset, setRangePreset] = useState<DateRangePreset>("all");
  const [customFrom, setCustomFrom] = useState("");
  const [customTo, setCustomTo] = useState("");
  const { from: dateFrom, to: dateTo } = resolveDateRange(rangePreset, customFrom, customTo);
  // ADR-108 decision 8 — lazy initializer, same pattern ThemeContext.tsx
  // already uses for its own localStorage-backed preference (accepted
  // in this codebase over a setState-in-effect read).
  const [hiddenColumns, setHiddenColumns] = useState<Set<ToggleableColumnKey>>(() => loadHiddenColumns());
  const [columnsMenuOpen, setColumnsMenuOpen] = useState(false);
  const [columnsTriggerEl, setColumnsTriggerEl] = useState<HTMLElement | null>(null);
  function toggleColumn(key: ToggleableColumnKey) {
    setHiddenColumns((prev) => {
      const next = new Set(prev);
      if (next.has(key)) next.delete(key);
      else next.add(key);
      try {
        localStorage.setItem(COLUMNS_STORAGE_KEY, JSON.stringify([...next]));
      } catch {
        // per-viewer convenience only — a failed write just means the
        // preference doesn't persist this session, nothing to surface.
      }
      return next;
    });
  }
  const [exporting, setExporting] = useState(false);
  const [lastUpdatedAt, setLastUpdatedAt] = useState<Date | null>(null);
  // Memoized so the fetch effect below can list this one value in its
  // deps instead of every individual field — a fresh object literal on
  // every render would otherwise refire the effect every render too.
  const orderFilters = useMemo(
    () => ({ status, search: search || undefined, page: pageNumber, source: source || undefined, gameId: gameId || undefined, dateFrom, dateTo }),
    [status, search, pageNumber, source, gameId, dateFrom, dateTo],
  );
  // Adjusted during render (React's own pattern for "reset state when
  // other state changes"), not in an effect — resets pagination to 1
  // whenever the filter/search changes, without a synchronous setState
  // call inside an effect.
  const [paginationFilterKey, setPaginationFilterKey] = useState({ status, search, source, gameId, dateFrom, dateTo });
  if (
    paginationFilterKey.status !== status ||
    paginationFilterKey.search !== search ||
    paginationFilterKey.source !== source ||
    paginationFilterKey.gameId !== gameId ||
    paginationFilterKey.dateFrom !== dateFrom ||
    paginationFilterKey.dateTo !== dateTo
  ) {
    setPaginationFilterKey({ status, search, source, gameId, dateFrom, dateTo });
    setPageNumber(1);
  }
  const [page, setPage] = useState<OrderPage | null>(null);
  const [summary, setSummary] = useState<OrderSummary | null>(null);
  const [error, setError] = useState<string | null>(null);

  const [selected, setSelected] = useState<OrderDetail | null>(null);
  const [resendModalOpen, setResendModalOpen] = useState(false);
  const [resendMessage, setResendMessage] = useState<string | null>(null);
  const [voucherModalOpen, setVoucherModalOpen] = useState(false);
  const [voucherMessage, setVoucherMessage] = useState<string | null>(null);
  const [markDeliveredModalOpen, setMarkDeliveredModalOpen] = useState(false);
  const [markDeliveredMessage, setMarkDeliveredMessage] = useState<string | null>(null);
  const [confirmFailedModalOpen, setConfirmFailedModalOpen] = useState(false);
  const [confirmFailedMessage, setConfirmFailedMessage] = useState<string | null>(null);
  const [refundingToWallet, setRefundingToWallet] = useState(false);
  const [refundMessage, setRefundMessage] = useState<string | null>(null);
  // ADR-094 decision 10: a combo order's only working recovery path —
  // resendOrderDelivery() (package-swap) always 422s for a combo
  // (found live, 2026-09-15 smoke test), so combo orders get this
  // direct action instead of ResendDeliveryModal.
  const [retryingDelivery, setRetryingDelivery] = useState(false);
  const [retryMessage, setRetryMessage] = useState<string | null>(null);
  // ADR-102 decision 3 — the mandatory free-text reason required to
  // override a scoped-unsafe Retry (combo path only; the non-combo
  // Resend Delivery modal has its own copy of this field).
  const [retryOverrideReason, setRetryOverrideReason] = useState("");

  async function openOrder(token: string, id: number) {
    setSelected(null);
    setResendMessage(null);
    setVoucherMessage(null);
    setMarkDeliveredMessage(null);
    setRefundMessage(null);
    try {
      setSelected(await getOrder(token, id));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load this order.");
    }
  }

  /**
   * ADR-096 — re-fetches the currently open order in place (no
   * flash back to the list, unlike openOrder()'s own setSelected(null)
   * reset) after a manual "Check from Supplier"/"Check from Gateway"
   * applies an outcome. Silent on failure, same as handleResent()'s own
   * background poll — the admin can just click the button again.
   */
  async function refreshSelected() {
    const session = getClientSession();
    if (!session || !selected) return;
    try {
      setSelected(await getOrder(session.token, selected.id));
    } catch {
      // Silent — see doc comment above.
    }
  }

  const pollTimersRef = useRef<ReturnType<typeof setTimeout>[]>([]);

  useEffect(() => {
    return () => {
      pollTimersRef.current.forEach(clearTimeout);
    };
  }, []);

  function handleResent() {
    if (!session || !selected) return;
    setResendMessage("Resend queued — waiting for delivery outcome...");

    pollTimersRef.current.forEach(clearTimeout);
    pollTimersRef.current = [];

    const orderId = selected.id;
    const initialAttemptsCount = selected.resend_attempts?.length ?? 0;
    const initialDeliveryStatus = selected.delivery_status;

    const delays = [1500, 3500, 6000, 9000];
    let resolved = false;

    delays.forEach((delay, index) => {
      const timer = setTimeout(async () => {
        if (resolved) return;
        try {
          const fresh = await getOrder(session.token, orderId);
          const hasNewAttempt = (fresh.resend_attempts?.length ?? 0) > initialAttemptsCount;
          const statusChanged = fresh.delivery_status !== initialDeliveryStatus;

          if (hasNewAttempt || statusChanged) {
            resolved = true;
            setSelected(fresh);
            // Refresh orders table in background
            listOrders(session.token, orderFilters)
              .then(setPage)
              .catch(() => {});

            if (fresh.delivery_status === "delivered") {
              setResendMessage("Resend delivered successfully! Delivery logs updated.");
            } else if (fresh.delivery_status === "failed") {
              setResendMessage("Resend attempt finished (delivery failed) — see latest Delivery Logs entry below.");
            } else {
              setResendMessage("Resend processed — delivery logs and order status updated.");
            }
          } else if (index === delays.length - 1) {
            setSelected(fresh);
            setResendMessage("Resend is still processing in background. You can refresh again in a moment.");
          }
        } catch {
          // Silent ignore during background poll
        }
      }, delay);
      pollTimersRef.current.push(timer);
    });
  }

  /**
   * ADR-024 addendum (2026-09-17, restore-only) — `restored_only`
   * branches the success message and local state update: a full-cover
   * order restores its original voucher but mints no new one, so
   * `voucher` stays null and `has_voucher_restored` is what flips
   * (mirroring the backend's own `has_voucher_restored` badge) — that's
   * what hides the button on this same render, not `voucher`.
   */
  function handleVoucherIssued({ restored_only, voucher }: IssueVoucherResult) {
    setVoucherMessage(
      restored_only
        ? "Voucher restored — order fully covered by voucher, no new voucher issued."
        : `Voucher ${voucher?.code} issued.`,
    );
    setSelected((current) =>
      current ? { ...current, voucher, has_voucher_restored: restored_only || current.has_voucher_restored } : current,
    );
  }

  function handleMarkedDelivered(updated: OrderDetail) {
    setMarkDeliveredMessage("Delivery confirmed manually — ledger profit credited.");
    setSelected(updated);
  }

  function handleConfirmedFailed(updated: OrderDetail) {
    setConfirmFailedMessage("Delivery confirmed genuinely failed — Issue Voucher is now available.");
    setSelected(updated);
  }

  async function handleRefundToWallet() {
    const session = getClientSession();
    if (!session || !selected) return;
    setRefundingToWallet(true);
    try {
      const updated = await refundOrderToWallet(session.token, selected.id);
      setRefundMessage(`Refunded ${formatRm(updated.final_amount)} to ${updated.wallet_reseller?.business_name}'s wallet.`);
      setSelected(updated);
    } catch (err) {
      setRefundMessage(err instanceof ApiError ? err.message : "Refund failed.");
    } finally {
      setRefundingToWallet(false);
    }
  }

  /** ADR-094 decision 10 — the plain retry, no package picker (a combo can't swap package anyway). */
  async function handleRetryDelivery() {
    const session = getClientSession();
    if (!session || !selected) return;
    setRetryingDelivery(true);
    setRetryMessage(null);
    try {
      await retryOrderDelivery(session.token, selected.id, {
        override_reason: retryOverrideReason.trim() || undefined,
      });
      setRetryOverrideReason("");
      setRetryMessage("Delivery retry queued — refresh in a moment to see the result.");
    } catch (err) {
      setRetryMessage(err instanceof ApiError ? err.message : "Could not queue the retry.");
    } finally {
      setRetryingDelivery(false);
    }
  }

  /**
   * ADR-108 decision 9 (ORD-5) — respects every filter currently active
   * on the screen (status/search/source/game/date-range), not just the
   * page an admin happens to be viewing.
   */
  async function handleExport() {
    const s = getClientSession();
    if (!s) return;
    setExporting(true);
    setError(null);
    try {
      await exportOrders(s.token, orderFilters);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not export orders.");
    } finally {
      setExporting(false);
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  useEffect(() => {
    if (!session) return;

    listOrders(session.token, orderFilters)
      .then((p) => {
        setPage(p);
        setLastUpdatedAt(new Date());
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load orders.");
      });

  }, [session, orderFilters]);

  /** Manual "Refresh" button — re-runs the same fetch the effect above already does, on demand. */
  function handleManualRefresh() {
    if (!session) return;
    listOrders(session.token, orderFilters)
      .then((p) => {
        setPage(p);
        setLastUpdatedAt(new Date());
      })
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load orders.");
      });
  }

  // ADR-108 decision 6 — the Game filter's own dropdown options, loaded
  // once (games rarely change mid-session; a fresh admin session just
  // reloads the page).
  useEffect(() => {
    if (!session) return;

    listGames(session.token).then(setGames).catch(() => {});
  }, [session]);

  // ADR-092: decoupled from the listOrders() effect above — the six
  // counts don't depend on search/filter/page, and shouldn't be
  // refetched by them; this effect owns its own 60s poll instead.
  useEffect(() => {
    if (!session) return;

    let cancelled = false;
    const fetchSummary = () => {
      getOrderSummary(session.token)
        .then((s) => {
          if (!cancelled) setSummary(s);
        })
        .catch(() => {
          // Silent — the cards just keep showing their last-known counts.
        });
    };

    fetchSummary();
    const interval = setInterval(fetchSummary, SUMMARY_POLL_MS);
    return () => {
      cancelled = true;
      clearInterval(interval);
    };
  }, [session]);

  // ORD-6 deep-link — Customer Analytics' Order History rows link here
  // as /admin/orders?order={id}. A plain .then()/.catch() chain, not
  // openOrder() (which resets several message states synchronously as
  // its first statements) — calling that directly in an effect body
  // would reintroduce the exact set-state-in-effect pattern this
  // codebase already had a dedicated cleanup pass for.
  const orderIdParam = searchParams.get("order");

  useEffect(() => {
    if (!session || !orderIdParam) return;

    const id = Number(orderIdParam);
    if (!Number.isFinite(id)) return;

    getOrder(session.token, id)
      .then(setSelected)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load this order.");
      });
  }, [session, orderIdParam]);

  if (selected) {
    return (
      <>
      <div>
        <button
          onClick={() => {
            setSelected(null);
            // Drop the deep-link query param too, or navigating "back"
            // would still point at this same order on refresh/re-mount.
            if (orderIdParam) router.replace("/admin/orders");
          }}
          className="mb-4 text-sm text-ink-muted hover:text-ink"
        >
          ← Back to orders
        </button>

        <div className="mb-6">
          {/* ADR-104 PR-2b: header action-bar — every conditional action
              button below (labels/conditions/handlers all byte-for-byte
              unchanged from before this PR) now renders next to the
              title/tags instead of scattered in a row further down the
              page. Pure repositioning, grilled + approved as layout-only —
              no button added/removed/relabeled, no condition touched. */}
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h1 className="text-page-title font-semibold text-ink">{selected.order_number}</h1>
              <p className="mt-1 flex items-center gap-3 text-sm text-ink-muted">
                <StatusText severity={paymentStatusSeverity[selected.payment_status]}>
                  payment: {selected.payment_status}
                </StatusText>
                <StatusText severity={deliveryStatusSeverity[selected.delivery_status]}>
                  delivery: {selected.delivery_status}
                </StatusText>
              </p>
            </div>

            <div className="flex flex-wrap items-center gap-3">
              {/* ADR-017: one action for "fix a failed delivery" — defaults to resending the same package (the old plain "Retry Delivery" behavior), with the option to swap packages inside the modal. A failed or needs_review delivery can be resent (ADR-026 decision 4b) — mirrors the backend guard exactly.
                  ADR-094 decision 10 (found live, 2026-09-15): a combo order (`delivery_legs.length > 0`) can never swap package — resendOrderDelivery() always 422s for one — so it gets the plain retry action instead, never this modal. */}
              {(selected.delivery_status === "failed" || selected.delivery_status === "needs_review") && (
                <>
                  {/* ADR-102 decision 1: hidden once this order is already compensated (voucher issued or wallet refunded) — mirrors the backend's own hard block, so an admin never sees a button that would just 400. */}
                  {!selected.voucher && !selected.wallet_refunded && (
                    selected.delivery_legs.length > 0 ? (
                      <div className="flex flex-wrap items-center gap-2">
                        {/* ADR-102 decision 3: a combo order still checks the futile flag regardless of failed/needs_review — the plain Retry button requires the same mandatory override reason the Resend Delivery modal collects for a non-combo order. */}
                        {selected.resend_unsafe_to_override && (
                          <Input
                            aria-label="Override reason"
                            placeholder="Reason to override and retry anyway (required)"
                            value={retryOverrideReason}
                            onChange={(e) => setRetryOverrideReason(e.target.value)}
                            className="w-72"
                          />
                        )}
                        {/* ADR-104: outlined, matching the mockup's uniform
                            neutral header-button treatment — same handler/
                            label/disabled logic, appearance only. */}
                        <Button
                          size="small"
                          variant="outlined"
                          disabled={retryingDelivery || (selected.resend_unsafe_to_override && retryOverrideReason.trim() === "")}
                          onClick={handleRetryDelivery}
                        >
                          {retryingDelivery ? "Retrying…" : "Retry Delivery…"}
                        </Button>
                      </div>
                    ) : (
                      <Button size="small" variant="outlined" onClick={() => setResendModalOpen(true)}>
                        Resend Delivery…
                      </Button>
                    )
                  )}
                  {/* ADR-073 decision 7: a wallet-owned order gets "Refund to Wallet" INSTEAD of "Issue Voucher" — never both, Voucher's email-keyed mechanism has no meaning for a B2B wallet account. */}
                  {selected.delivery_status === "failed" && selected.wallet_reseller && !selected.wallet_refunded && (
                    <Button size="small" variant="outlined" disabled={refundingToWallet} onClick={handleRefundToWallet}>
                      {refundingToWallet ? "Refunding…" : "Refund to Wallet…"}
                    </Button>
                  )}
                  {/* ADR-004/ORD-7: the other resolution path — hidden once a voucher has already been issued for this order (at most one, enforced by a real unique index on the backend, not just this check), and never shown for the ordinary ambiguous needs_review case at all (ADR-026 decision 4c). ADR-094 decision 9's carve-out: a genuine partial-delivery combo order (`partial_combo_delivery`) is the one needs_review case this button does appear for. ADR-024 addendum (2026-09-17, restore-only): also hidden once `has_voucher_restored` — a full-cover order never gets a `voucher` row, so that check alone would leave this button visible forever; label swaps to "Restore Voucher…" for the same case, decided before the admin clicks anything. */}
                  {(selected.delivery_status === "failed" || selected.partial_combo_delivery) && !selected.wallet_reseller && !selected.voucher && !selected.has_voucher_restored && (
                    <Button size="small" variant="outlined" onClick={() => setVoucherModalOpen(true)}>
                      {isRestoreOnly(selected) ? "Restore Voucher…" : "Issue Voucher…"}
                    </Button>
                  )}
                  {/* ADR-026 decision 4a — the one needs_review exit that isn't a retry. ADR-102 decision 1: hidden once already compensated, same reasoning as the Retry/Resend button above. */}
                  {selected.delivery_status === "needs_review" && !selected.voucher && !selected.wallet_refunded && (
                    <Button size="small" variant="outlined" onClick={() => setMarkDeliveredModalOpen(true)}>
                      Mark as Delivered…
                    </Button>
                  )}
                  {/* ADR-026 addendum (2026-09-16) — the other exit decision 4c's own text always assumed existed. Excluded for a genuine partial-combo-delivery needs_review order — that case has its own custom-amount Issue Voucher path instead (some legs really did deliver). ADR-102 decision 1: hidden once already compensated, same reasoning as the Retry/Resend button above. */}
                  {selected.delivery_status === "needs_review" && !selected.partial_combo_delivery && !selected.voucher && !selected.wallet_refunded && (
                    <Button size="small" variant="outlined" severity="danger" onClick={() => setConfirmFailedModalOpen(true)}>
                      Confirm Failed…
                    </Button>
                  )}
                </>
              )}
              {/* ADR-096 — independent of the failed/needs_review block above:
                  a Pending delivery_status/payment_status is an async supplier/
                  gateway awaiting confirmation, not a failure needing retry. */}
              {session && <ManualCheckButtons order={selected} token={session.token} onChecked={refreshSelected} />}
            </div>
          </div>

          {/* ADR-104 PR-2b: compact summary strip — the same fields already
              shown in the cards below (Customer Details / Game·Package /
              Pricing Details, plus created_at), just surfaced at a glance
              next to the header. No new data, no new fetch, no new field. */}
          <div className="mt-4 grid grid-cols-2 gap-4 rounded-lg border border-gray-200 bg-subtle p-4 dark:border-gray-800 sm:grid-cols-5">
            <div>
              <p className="text-theme-xs text-ink-muted">Customer</p>
              <p className="text-theme-sm font-medium text-ink">{selected.customer_email}</p>
            </div>
            <div>
              <p className="text-theme-xs text-ink-muted">Game · Package</p>
              <p className="text-theme-sm font-medium text-ink">
                {selected.game?.name ?? "—"}
                {selected.package?.name && <span className="text-ink-muted"> · {selected.package.name}</span>}
              </p>
            </div>
            <div>
              <p className="text-theme-xs text-ink-muted">Amount</p>
              <p className="text-theme-sm font-medium text-ink">
                {formatRm(selected.final_amount)}
                {selected.payment_method && <span className="text-ink-muted"> · {selected.payment_method}</span>}
              </p>
            </div>
            {/* ADR-104: same derived value OrderDetailCards' "Channel" row
                already computes (wallet_reseller presence — no new data,
                no new logic) surfaced here too for the 5-column strip. */}
            <div>
              <p className="text-theme-xs text-ink-muted">Channel</p>
              <p className="text-theme-sm font-medium text-ink">{selected.wallet_reseller ? "Reseller" : "Direct"}</p>
            </div>
            <div>
              <p className="text-theme-xs text-ink-muted">Created</p>
              <p className="text-theme-sm font-medium text-ink">{new Date(selected.created_at).toLocaleString()}</p>
            </div>
          </div>

          {/* ADR-026: the ambiguous-outcome case — cross-reference banner, plus "Mark as Delivered" instead of "Issue Voucher" (decision 4c: voucher issuance is deliberately never available from this state). */}
          {selected.delivery_status === "needs_review" && <NeedsReviewBanner order={selected} />}

          {/* ADR-026 addendum (2026-09-16), renamed by ADR-102 decision 3/5 — server-computed from the persisted error_code (ADR-098's own rc table), never a second hand-copied list here. Text only now — its Retry/Resend button moved into the header action-bar above (PR-2b). */}
          {selected.delivery_retry_unsafe_with_same_reference && (
            <p className="mt-3 text-sm text-warning-600 dark:text-orange-400">
              Resending is unlikely to change this outcome — the supplier already recorded a final result for this reference. A package swap does not escape this either.
            </p>
          )}

          {(resendMessage || voucherMessage || refundMessage || retryMessage) && (
            <div className="mt-3 flex flex-wrap items-center gap-3">
              {resendMessage && <span className="text-sm text-ink-muted">{resendMessage}</span>}
              {voucherMessage && <span className="text-sm text-ink-muted">{voucherMessage}</span>}
              {refundMessage && <span className="text-sm text-ink-muted">{refundMessage}</span>}
              {retryMessage && <span className="text-sm text-ink-muted">{retryMessage}</span>}
            </div>
          )}
          {/* Rendered outside the failed/needs_review-gated block above,
              deliberately — unlike resend/voucher, a successful Mark as
              Delivered moves delivery_status to "delivered" in the same
              render that sets this message, which would otherwise
              unmount it before an admin ever saw it (found live via the
              ADR-023 admin-mark-delivered E2E spec). */}
          {markDeliveredMessage && (
            <p className="mt-3 text-sm text-ink-muted">{markDeliveredMessage}</p>
          )}
          {/* Rendered outside the failed/needs_review-gated block above,
              deliberately — same reasoning as markDeliveredMessage above:
              a successful Confirm Failed moves delivery_status to
              "failed" in the same render that sets this message. */}
          {confirmFailedMessage && (
            <p className="mt-3 text-sm text-ink-muted">{confirmFailedMessage}</p>
          )}
          {/* ADR-102 decision 11 — three independent cards (Voucher Used to Pay / Compensation Voucher Issued / Wallet Refund), replacing the old single-line mentions. */}
          <RefundInformationCards order={selected} />
        </div>

        <OrderDetailCards order={selected} />

        {/* ADR-094 decision 12: empty/no-op for every ordinary order — only a combo order has legs to show. */}
        <ComboLegBreakdown legs={selected.delivery_legs} />

        {/* ADR-017 decision #4: chronological delivery history (initial + resends) */}
        <DeliveryLogsTable order={selected} attempts={selected.resend_attempts} />
      </div>

      {session && (
        <>
          <ResendDeliveryModal
            isOpen={resendModalOpen}
            onClose={() => setResendModalOpen(false)}
            onResent={handleResent}
            order={selected}
            token={session.token}
          />
          <IssueVoucherModal
            isOpen={voucherModalOpen}
            onClose={() => setVoucherModalOpen(false)}
            onIssued={handleVoucherIssued}
            order={selected}
            token={session.token}
          />
          <MarkDeliveredModal
            isOpen={markDeliveredModalOpen}
            onClose={() => setMarkDeliveredModalOpen(false)}
            onConfirmed={handleMarkedDelivered}
            order={selected}
            token={session.token}
          />
          <ConfirmFailedModal
            isOpen={confirmFailedModalOpen}
            onClose={() => setConfirmFailedModalOpen(false)}
            onConfirmed={handleConfirmedFailed}
            order={selected}
            token={session.token}
          />
        </>
      )}
    </>
    );
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-page-title font-semibold text-ink">Orders</h1>
          <p className="mt-1 text-sm text-ink-muted">
            Every order created via checkout — real customer purchases only.
          </p>
        </div>
        {/* ADR-108 decision 10 (layout) — Refresh + last-updated + Export CSV, top-right, matching the founder's own mockup. */}
        <div className="flex items-center gap-3">
          {lastUpdatedAt && (
            <span className="flex items-center gap-1.5 text-theme-xs text-ink-muted">
              <span className="size-1.5 rounded-full bg-success-500" />
              Updated {lastUpdatedAt.toLocaleTimeString([], { hour: "2-digit", minute: "2-digit" })}
            </span>
          )}
          <Button size="small" variant="outlined" onClick={handleManualRefresh}>
            Refresh
          </Button>
          <Button size="small" variant="outlined" onClick={handleExport} disabled={exporting}>
            {exporting ? "Exporting…" : "Export CSV"}
          </Button>
        </div>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <OrderSummaryCards summary={summary} activeStatus={status} onSelect={setStatus} />

      <div className="mb-4 flex flex-wrap items-center gap-3">
        <input
          type="text"
          placeholder="Search order #, email or game…"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="h-11 w-full max-w-sm rounded-lg border border-gray-300 px-4 py-2.5 text-sm shadow-theme-xs focus:border-cyan-600 focus:outline-hidden focus:ring-3 focus:ring-focus-ring/10 dark:border-gray-700 dark:bg-gray-900 dark:text-ink"
        />
        {/* ADR-104: underline-tab style, not a filled pill — same values/
            labels/onClick as before, matching the artifact's FilterBar. */}
        <div className="flex gap-1 border-b border-gray-200 dark:border-gray-800">
          {STATUS_FILTERS.map((f) => (
            <button
              key={f.value}
              onClick={() => setStatus(f.value)}
              className={`border-b-2 px-3 py-1.5 text-sm font-medium ${
                status === f.value
                  ? "border-cyan-600 text-cyan-ink"
                  : "border-transparent text-ink-muted hover:text-ink"
              }`}
            >
              {f.label}
            </button>
          ))}
        </div>
      </div>

      {/* ADR-108 decisions 5-8 — Source/Game/date-range filters + Columns toggle, orthogonal to the status tabs above. */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <Select value={source} options={SOURCE_FILTERS} optionLabel="label" optionValue="value" onValueChange={(e) => setSource(e.value as OrderSourceFilter | "")}>
          <SelectTrigger className="min-w-[9rem]">
            <SelectValue />
            <SelectIndicator />
          </SelectTrigger>
          <SelectPortal>
            <SelectPositioner>
              <SelectPopup>
                <SelectList>
                  {SOURCE_FILTERS.map((opt, index) => (
                    <SelectOption key={opt.value || "all"} index={index}>
                      {opt.label}
                    </SelectOption>
                  ))}
                </SelectList>
              </SelectPopup>
            </SelectPositioner>
          </SelectPortal>
        </Select>

        <Select
          value={gameId === "" ? "" : String(gameId)}
          options={[{ label: "All games", value: "" }, ...games.map((g) => ({ label: g.name, value: String(g.id) }))]}
          optionLabel="label"
          optionValue="value"
          onValueChange={(e) => setGameId(e.value ? Number(e.value) : "")}
        >
          <SelectTrigger className="min-w-[10rem]">
            <SelectValue />
            <SelectIndicator />
          </SelectTrigger>
          <SelectPortal>
            <SelectPositioner>
              <SelectPopup>
                <SelectList>
                  <SelectOption key="all" index={0}>
                    All games
                  </SelectOption>
                  {games.map((g, index) => (
                    <SelectOption key={g.id} index={index + 1}>
                      {g.name}
                    </SelectOption>
                  ))}
                </SelectList>
              </SelectPopup>
            </SelectPositioner>
          </SelectPortal>
        </Select>

        <Select value={rangePreset} options={DATE_RANGE_PRESETS} optionLabel="label" optionValue="value" onValueChange={(e) => setRangePreset(e.value as DateRangePreset)}>
          <SelectTrigger className="min-w-[9rem]">
            <SelectValue />
            <SelectIndicator />
          </SelectTrigger>
          <SelectPortal>
            <SelectPositioner>
              <SelectPopup>
                <SelectList>
                  {DATE_RANGE_PRESETS.map((opt, index) => (
                    <SelectOption key={opt.value} index={index}>
                      {opt.label}
                    </SelectOption>
                  ))}
                </SelectList>
              </SelectPopup>
            </SelectPositioner>
          </SelectPortal>
        </Select>
        {rangePreset === "custom" && (
          <>
            <Input type="date" value={customFrom} onChange={(e) => setCustomFrom(e.target.value)} className="w-[9.5rem]" />
            <span className="text-theme-xs text-gray-400 dark:text-gray-500">to</span>
            <Input type="date" value={customTo} onChange={(e) => setCustomTo(e.target.value)} className="w-[9.5rem]" />
          </>
        )}

        {/* Manual anchor pattern (UserDropdown.tsx's own precedent) — Button
            isn't a forwardRef component, so a wrapping span carries the ref
            Popover's `anchor` prop needs. */}
        <span ref={setColumnsTriggerEl}>
          <Button size="small" variant="outlined" onClick={() => setColumnsMenuOpen((v) => !v)}>
            Columns
          </Button>
        </span>
        <Popover open={columnsMenuOpen} onOpenChange={(e) => setColumnsMenuOpen(e.value ?? false)} anchor={columnsTriggerEl}>
          <PopoverPortal>
            <PopoverPositioner side="bottom" align="start" sideOffset={6}>
              <PopoverPopup className="w-56 p-3">
                <p className="mb-2 text-theme-xs font-medium text-ink-muted">Show columns</p>
                <div className="flex flex-col gap-1.5">
                  {TOGGLEABLE_COLUMNS.map((col) => (
                    <label key={col.key} className="flex items-center gap-2 text-theme-sm text-ink">
                      <input
                        type="checkbox"
                        checked={!hiddenColumns.has(col.key)}
                        onChange={() => toggleColumn(col.key)}
                        className="size-3.5 rounded border-gray-300 dark:border-gray-700"
                      />
                      {col.label}
                    </label>
                  ))}
                </div>
              </PopoverPopup>
            </PopoverPositioner>
          </PopoverPortal>
        </Popover>
      </div>

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-surface dark:border-gray-800">
        <div className="max-w-full overflow-x-auto">
          <DataTable data={page?.data ?? []} dataKey="id">
            <DataTableTableContainer>
              <DataTableTable>
                <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                  <DataTableTHeadRow>
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Order #</DataTableTHeadCell>
                    {!hiddenColumns.has("customer") && <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Customer</DataTableTHeadCell>}
                    {!hiddenColumns.has("game") && <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Game / Package</DataTableTHeadCell>}
                    {!hiddenColumns.has("source") && <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Source</DataTableTHeadCell>}
                    {!hiddenColumns.has("amount") && <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Final Amount</DataTableTHeadCell>}
                    {!hiddenColumns.has("payment") && <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Payment</DataTableTHeadCell>}
                    {!hiddenColumns.has("delivery") && <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Delivery</DataTableTHeadCell>}
                    {!hiddenColumns.has("date") && <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Date</DataTableTHeadCell>}
                    <DataTableTHeadCell className="px-5 py-3 text-start text-theme-xs font-medium text-ink-muted">Actions</DataTableTHeadCell>
                  </DataTableTHeadRow>
                </DataTableTHead>
                <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {({ item }) => {
                    const order = item as unknown as OrderListItem;

                    return (
                      <DataTableRow key={order.id}>
                        <DataTableCell className="px-5 py-4 font-mono text-code-id font-medium text-ink">{order.order_number}</DataTableCell>
                        {!hiddenColumns.has("customer") && <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">{order.customer_email}</DataTableCell>}
                        {!hiddenColumns.has("game") && (
                          <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                            {order.game?.name ?? "—"}
                            {order.package?.name && <span className="text-theme-xs text-gray-400"> · {order.package.name}</span>}
                          </DataTableCell>
                        )}
                        {!hiddenColumns.has("source") && (
                          <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                            {/* A wallet order's own affiliate is always the platform's primary brand (ADR-073 decision 5) — wallet_reseller is the one that actually answers "where from". ADR-108 decision 5 — "Direct" for the primary brand's own order, a real name only for a genuine partner Affiliate (both carry a non-null affiliate, only is_primary tells them apart). */}
                            {order.wallet_reseller ? (
                              <span>
                                Reseller: <span className="font-medium text-ink">{order.wallet_reseller.business_name}</span>
                              </span>
                            ) : order.affiliate && !order.affiliate.is_primary ? (
                              order.affiliate.business_name
                            ) : (
                              "Direct"
                            )}
                          </DataTableCell>
                        )}
                        {/* ADR-104: two-line cell (main value + a quiet sub-line) —
                            same fields already on OrderListItem, no data/label
                            change, just how the existing payment_method reads
                            here. */}
                        {!hiddenColumns.has("amount") && (
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <span className="font-medium text-ink">{formatRm(order.final_amount)}</span>
                            {order.payment_method && (
                              <div className="text-theme-xs text-ink-muted">{order.payment_method}</div>
                            )}
                          </DataTableCell>
                        )}
                        {!hiddenColumns.has("payment") && (
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <StatusText severity={paymentStatusSeverity[order.payment_status]}>{order.payment_status}</StatusText>
                          </DataTableCell>
                        )}
                        {!hiddenColumns.has("delivery") && (
                          <DataTableCell className="px-5 py-4 text-theme-sm">
                            <div className="flex flex-wrap items-center gap-1.5">
                              <StatusText severity={deliveryStatusSeverity[order.delivery_status]}>{order.delivery_status}</StatusText>
                              {/* ADR-108 decision 2 — at most one compensation Tag, priority-picked (see primaryCompensationTag()); was up to 4 stacked pills (ADR-102 decision 12 / ADR-024 addendum), decluttered here. Order Detail's RefundInformationCards still shows every fact in full. */}
                              {(() => {
                                const tag = primaryCompensationTag(order);
                                return tag && <Tag severity={tag.severity}>{tag.label}</Tag>;
                              })()}
                            </div>
                          </DataTableCell>
                        )}
                        {!hiddenColumns.has("date") && (
                          <DataTableCell className="px-5 py-4 text-theme-sm text-ink-muted">
                            {new Date(order.created_at).toLocaleString()}
                          </DataTableCell>
                        )}
                        <DataTableCell className="px-5 py-4 text-theme-sm">
                          {/* ADR-104: label stays "View" — same button, same
                              action, same destination — only its emphasis
                              (filled vs outlined) follows the delivery
                              status, matching the artifact's own Failed-row
                              treatment. No new button, no relabel. */}
                          <Button
                            size="small"
                            variant={order.delivery_status === "failed" ? undefined : "outlined"}
                            onClick={() => session && openOrder(session.token, order.id)}
                          >
                            View
                          </Button>
                        </DataTableCell>
                      </DataTableRow>
                    );
                  }}
                </DataTableTBody>
              </DataTableTable>
            </DataTableTableContainer>
          </DataTable>

          {page?.data.length === 0 && (
            <p className="p-6 text-center text-sm text-ink-muted">No orders found.</p>
          )}
          {page === null && !error && (
            <p className="p-6 text-center text-sm text-ink-muted">Loading…</p>
          )}
        </div>
      </div>

      {page && page.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-sm text-ink-muted">
          <span>Page {page.current_page} of {page.last_page} ({page.total} total)</span>
          <div className="flex gap-2">
            <Button size="small" variant="outlined" disabled={page.current_page <= 1} onClick={() => setPageNumber((p) => p - 1)}>
              Previous
            </Button>
            <Button size="small" variant="outlined" disabled={page.current_page >= page.last_page} onClick={() => setPageNumber((p) => p + 1)}>
              Next
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
