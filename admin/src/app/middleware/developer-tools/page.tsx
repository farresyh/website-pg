"use client";

/**
 * ADR-054 (DEV-1/2, MUI-11) — Developer raw API tester. One screen
 * satisfies both DEV-1/2 (§6.18) and MUI-11 (§6.20). Every call goes
 * through the real `SupplierAdapter` server-side — this page edits a
 * typed request's fields, never a raw HTTP body (decision 2).
 * Dry-run (default on) never reaches the network (decision 3);
 * `createOrder` is blocked server-side when live-fired against a
 * supplier not confirmed sandbox/testing (decision 4). PrimeReact-
 * Tailwind only (ADR-038), same testbed pattern as the Backups screen.
 *
 * Layout: pick a supplier first, then a sidebar lists its available
 * endpoints (the same 5 `SupplierAdapter` methods every supplier
 * exposes — this doesn't add any new backend capability, just
 * presents the existing 5 as a per-supplier endpoint list rather than
 * a single method dropdown).
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
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
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { type Supplier, listSuppliers } from "@/lib/suppliers";
import {
  type DeveloperToolMethod,
  type DeveloperToolResult,
  testSupplierAdapter,
} from "@/lib/developer-tools";

const ENDPOINTS: { method: DeveloperToolMethod; label: string; description: string }[] = [
  { method: "checkBalance", label: "Check Balance", description: "Check this supplier's current account balance." },
  { method: "listProducts", label: "List Products", description: "List this supplier's full product/package catalog." },
  {
    method: "checkStatus",
    label: "Check Status",
    description: "Check the status of a previous order by its supplier reference.",
  },
  {
    method: "validatePlayer",
    label: "Validate Player",
    description: "Validate a player ID (and server ID) directly against this supplier.",
  },
  {
    method: "createOrder",
    label: "Create Order",
    description: "Create a real order with this supplier. Blocked when live-fired against a production-mode supplier.",
  },
];

// Decision 9 — a static per-adapter caveat rather than a dynamic
// per-call-type oracle. See feedback memory on Gamevion's sandbox flag
// being a blanket header (every call) vs. Digiflazz's `testing` flag
// only applying to its transaction-submission endpoint.
const SANDBOX_CAVEATS: Record<string, string> = {
  gamevion:
    "Applies to every call, including Check Balance — a sandboxed Gamevion account reports a different balance than production, not a scaled view of the real one.",
  digiflazz:
    "Only applies to Create Order — Check Balance and other read calls always hit Digiflazz's production endpoint regardless of this flag.",
};

const FIELD_LABELS: Record<string, string> = {
  supplier_ref: "Supplier reference (their order/invoice id)",
  product_ref: "Product ref (supplier's package code)",
  player_id: "Player ID",
  server_id: "Server ID",
  customer_phone: "Customer phone",
  callback_url: "Callback URL",
};

function fieldsFor(method: DeveloperToolMethod): string[] {
  switch (method) {
    case "checkStatus":
      return ["supplier_ref", "product_ref", "player_id", "server_id"];
    case "validatePlayer":
      return ["player_id", "server_id"];
    case "createOrder":
      return ["product_ref", "player_id", "server_id", "customer_phone", "callback_url"];
    default:
      return [];
  }
}

const inputClass =
  "w-full rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-theme-sm text-gray-800 dark:border-gray-800 dark:bg-white/[0.03] dark:text-white/90";

export default function DeveloperToolsPage() {
  const router = useRouter();
  const session = useClientSession();

  const [suppliers, setSuppliers] = useState<Supplier[]>([]);
  const [supplierId, setSupplierId] = useState("");
  const [method, setMethod] = useState<DeveloperToolMethod>("checkBalance");
  const [dryRun, setDryRun] = useState(true);
  const [payload, setPayload] = useState<Record<string, string>>({});
  const [running, setRunning] = useState(false);
  const [result, setResult] = useState<DeveloperToolResult | null>(null);
  const [error, setError] = useState<string | null>(null);

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
    listSuppliers(session.token).then(setSuppliers).catch(() => undefined);
  }, [session]);

  const supplier = suppliers.find((s) => String(s.id) === supplierId) ?? null;
  const notConfigured = supplier !== null && !supplier.is_fully_configured;
  const blocksLiveCreateOrder = !dryRun && method === "createOrder" && supplier?.is_sandbox !== true;
  const endpoint = ENDPOINTS.find((e) => e.method === method)!;

  function selectEndpoint(next: DeveloperToolMethod) {
    setMethod(next);
    setPayload({});
    setResult(null);
    setError(null);
  }

  async function handleRun() {
    if (!session || !supplier) return;
    setRunning(true);
    setError(null);
    setResult(null);
    try {
      const trimmedPayload = Object.fromEntries(
        Object.entries(payload).filter(([, value]) => value.trim() !== ""),
      );
      const response = await testSupplierAdapter(session.token, {
        supplier_id: supplier.id,
        method,
        dry_run: dryRun,
        payload: Object.keys(trimmedPayload).length > 0 ? trimmedPayload : undefined,
      });
      setResult(response);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not run this test.");
    } finally {
      setRunning(false);
    }
  }

  const supplierOptions = suppliers.map((s) => ({ label: s.name, value: String(s.id) }));

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Developer / API Tester</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Test the real supplier connection directly — separate from Sandbox, which tests this platform&apos;s own
          order lifecycle against a fake supplier. Dry-run builds and previews the exact request, with no network
          call. Real calls are logged in Request Logs with a <code>dev_test_</code> prefix.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="mb-6 max-w-md">
        <label className="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">Supplier</label>
        <Select
          value={supplierId}
          options={supplierOptions}
          optionLabel="label"
          optionValue="value"
          onValueChange={(e) => {
            setSupplierId(e.value as string);
            selectEndpoint("checkBalance");
          }}
        >
          <SelectTrigger className="w-full">
            <SelectValue placeholder="Select a supplier to see its endpoints" />
            <SelectIndicator />
          </SelectTrigger>
          <SelectPortal>
            <SelectPositioner>
              <SelectPopup>
                <SelectList>
                  {supplierOptions.map((option, index) => (
                    <SelectOption key={option.value} index={index}>
                      {option.label}
                    </SelectOption>
                  ))}
                </SelectList>
              </SelectPopup>
            </SelectPositioner>
          </SelectPortal>
        </Select>
      </div>

      {supplier && (
        <>
          <div className="mb-4 flex flex-wrap items-center gap-2 text-theme-xs">
            <Tag severity={supplier.is_fully_configured ? "success" : "danger"}>
              {supplier.is_fully_configured ? "Configured" : "Not configured"}
            </Tag>
            {supplier.is_sandbox !== null && (
              <Tag severity={supplier.is_sandbox ? "warn" : "info"}>
                {supplier.is_sandbox ? "Sandbox / Testing mode" : "Production mode"}
              </Tag>
            )}
            {SANDBOX_CAVEATS[supplier.slug] && (
              <span className="text-gray-500 dark:text-gray-400">{SANDBOX_CAVEATS[supplier.slug]}</span>
            )}
          </div>

          <div className="grid grid-cols-[260px_1fr] gap-6">
            <div className="h-fit rounded-2xl border border-gray-200 bg-white p-3 dark:border-gray-800 dark:bg-white/[0.03]">
              <p className="mb-2 px-2 text-theme-xs font-semibold text-gray-500 dark:text-gray-400">
                {supplier.name} Endpoints
              </p>
              <ul className="space-y-1">
                {ENDPOINTS.map((e) => (
                  <li key={e.method}>
                    <button
                      type="button"
                      onClick={() => selectEndpoint(e.method)}
                      className={`w-full rounded-lg px-2 py-2 text-left text-theme-sm ${
                        method === e.method
                          ? "bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-400"
                          : "text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-white/[0.03]"
                      }`}
                    >
                      <div>{e.label}</div>
                      <div className="font-mono text-theme-xs text-gray-400 dark:text-gray-500">
                        SupplierAdapter::{e.method}()
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            </div>

            <div className="space-y-6">
              <div className="space-y-4 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                <div>
                  <h2 className="text-theme-md font-semibold text-gray-800 dark:text-white/90">{endpoint.label}</h2>
                  <p className="mt-1 text-theme-sm text-gray-500 dark:text-gray-400">{endpoint.description}</p>
                </div>

                {fieldsFor(method).length > 0 && (
                  <div className="space-y-3">
                    <p className="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Parameters</p>
                    {fieldsFor(method).map((field) => (
                      <div key={field}>
                        <label className="mb-1 block text-theme-xs text-gray-500 dark:text-gray-400">
                          {FIELD_LABELS[field]}
                        </label>
                        <input
                          className={inputClass}
                          value={payload[field] ?? ""}
                          onChange={(e) => setPayload((p) => ({ ...p, [field]: e.target.value }))}
                        />
                      </div>
                    ))}
                  </div>
                )}

                {method === "createOrder" && (
                  <p className="text-theme-xs text-gray-500 dark:text-gray-400">
                    The reference number is generated automatically (<code>DEVTEST-…</code>) — it can&apos;t be typed
                    in and never collides with a real order&apos;s reference.
                  </p>
                )}

                <label className="flex items-center gap-2 text-theme-sm text-gray-800 dark:text-white/90">
                  <input type="checkbox" checked={dryRun} onChange={(e) => setDryRun(e.target.checked)} />
                  Dry run (preview only, no network call)
                </label>

                {blocksLiveCreateOrder && (
                  <p className="rounded-lg bg-warning-50 px-3 py-2 text-theme-xs text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
                    Create Order can&apos;t be live-fired against this supplier while it&apos;s in production mode
                    (or its sandbox/testing mode is unconfirmed). Switch it to sandbox/testing mode first, or keep
                    dry-run on.
                  </p>
                )}

                <Button onClick={handleRun} disabled={notConfigured || running || blocksLiveCreateOrder}>
                  {running ? "Running…" : dryRun ? "Preview Request" : "Execute Request"}
                </Button>
              </div>

              {result && (
                <div className="space-y-3 rounded-2xl border border-gray-200 bg-white p-5 dark:border-gray-800 dark:bg-white/[0.03]">
                  <div className="flex items-center gap-2">
                    <h3 className="text-theme-sm font-semibold text-gray-800 dark:text-white/90">Raw Response</h3>
                    <Tag severity={result.dry_run ? "info" : result.success ? "success" : "danger"}>
                      {result.dry_run ? "Dry run" : result.outcome ?? "unknown"}
                    </Tag>
                  </div>

                  {result.error_message && (
                    <p className="text-theme-sm text-error-600 dark:text-error-400">
                      [{result.error_code}] {result.error_message}
                    </p>
                  )}

                  {result.dry_run ? (
                    <div>
                      <p className="mb-1 text-theme-xs text-gray-500 dark:text-gray-400">Request that would be sent</p>
                      <pre className="max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-theme-xs dark:bg-white/[0.03]">
                        {result.request ? JSON.stringify(result.request, null, 2) : "(no request body for this call)"}
                      </pre>
                    </div>
                  ) : (
                    <div>
                      <p className="mb-1 text-theme-xs text-gray-500 dark:text-gray-400">Response Body</p>
                      <pre className="max-h-64 overflow-auto rounded-lg bg-gray-50 p-3 text-theme-xs dark:bg-white/[0.03]">
                        {JSON.stringify(result.data, null, 2)}
                      </pre>
                    </div>
                  )}
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );
}
