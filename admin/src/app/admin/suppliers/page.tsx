"use client";

/**
 * ADR-125 decision 60: Admin → Partners → Suppliers, super_admin only. Each
 * manual supplier as a partner: its WhatsApp group (where its orders are
 * posted and it replies `.done` / `.gagal`), its reply SLA and its active
 * switch. Switching it off takes its packages off sale (decision 64); on
 * needs a linked group (decision 56). Creating one and its funding mode stay
 * in Middleware → Suppliers; API suppliers never appear here.
 */

import { useEffect, useState } from "react";
import Link from "next/link";
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
import {
  Dialog,
  DialogPortal,
  DialogBackdrop,
  DialogPositioner,
  DialogPopup,
  DialogHeader,
  DialogHeaderActions,
  DialogClose,
  DialogTitle,
  DialogContent,
  DialogFooter,
} from "@/components/ui/dialog";
import { Times as CloseIcon } from "@primeicons/react/times";
import { Tag } from "@/components/ui/tag";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api-client";
import { useClientSession } from "@/hooks/useClientSession";
import WhatsAppGroupLinker from "@/components/whatsapp/WhatsAppGroupLinker";
import {
  listPartnerSuppliers,
  linkSupplierWhatsAppGroup,
  updateSupplierWhatsAppGroupStatus,
  updateSupplierSla,
  updatePartnerSupplierStatus,
  type PartnerSupplier,
} from "@/lib/partner-suppliers";

const TH = "px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400";
const TD = "px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400";

export default function PartnerSuppliersPage() {
  const session = useClientSession();
  const [suppliers, setSuppliers] = useState<PartnerSupplier[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState<number | null>(null);
  const [groupTarget, setGroupTarget] = useState<PartnerSupplier | null>(null);
  const [slaTarget, setSlaTarget] = useState<PartnerSupplier | null>(null);
  const [slaValue, setSlaValue] = useState("");
  const [offTarget, setOffTarget] = useState<PartnerSupplier | null>(null);

  function refresh() {
    return listPartnerSuppliers()
      .then((list) => {
        setSuppliers(list);
        setError(null);
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load suppliers."));
  }

  useEffect(() => {
    if (session) void refresh();
  }, [session]);

  async function guard(id: number, fn: () => Promise<unknown>) {
    setBusy(id);
    setError(null);
    try {
      await fn();
      await refresh();
      return true;
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Something went wrong.");
      return false;
    } finally {
      setBusy(null);
    }
  }

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Suppliers</h1>
        <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
          Manual suppliers who top up login orders by hand. Each one gets its orders in its own WhatsApp group and
          replies there. Add a new one in <Link href="/middleware/suppliers" className="underline">Middleware → Suppliers</Link>.
        </p>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">{error}</p>
      )}
      {suppliers === null && !error && <p className="text-sm text-gray-500 dark:text-gray-400">Loading…</p>}

      {suppliers !== null && (
        <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
          <div className="max-w-full overflow-x-auto">
            <DataTable data={suppliers} dataKey="id">
              <DataTableTableContainer>
                <DataTableTable>
                  <DataTableTHead className="border-b border-gray-100 dark:border-gray-800">
                    <DataTableTHeadRow>
                      <DataTableTHeadCell className={TH}>Supplier</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>WhatsApp group</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Reply SLA</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Waiting</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Status</DataTableTHeadCell>
                      <DataTableTHeadCell className={TH}>Actions</DataTableTHeadCell>
                    </DataTableTHeadRow>
                  </DataTableTHead>
                  <DataTableTBody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {({ item }) => {
                      const s = item as unknown as PartnerSupplier;
                      const group = s.whatsapp_groups.find((g) => g.is_active) ?? null;
                      return (
                        <DataTableRow key={s.id}>
                          <DataTableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                            {s.name}
                            <p className="text-theme-xs font-normal text-gray-500 dark:text-gray-400">
                              {s.funding_mode === "pay_after" ? "Pay after delivery" : "Prepaid"}
                            </p>
                          </DataTableCell>
                          <DataTableCell className={TD}>
                            {group ? (
                              <span className="font-mono text-theme-xs">{group.whatsapp_group_id}</span>
                            ) : (
                              <Tag severity="warn">No group</Tag>
                            )}
                          </DataTableCell>
                          <DataTableCell className={TD}>{s.reply_sla_minutes} min</DataTableCell>
                          <DataTableCell className={TD}>
                            {s.pending_orders ? (
                              <Link href="/admin/manual-queue" className="underline">{s.pending_orders}</Link>
                            ) : (
                              0
                            )}
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            <Tag severity={s.is_active ? "success" : "secondary"}>{s.is_active ? "active" : "inactive"}</Tag>
                          </DataTableCell>
                          <DataTableCell className="px-5 py-4">
                            <div className="flex flex-wrap gap-1.5">
                              <Button size="small" variant="outlined" onClick={() => setGroupTarget(s)}>
                                WhatsApp Group
                              </Button>
                              <Button
                                size="small"
                                variant="outlined"
                                onClick={() => {
                                  setSlaTarget(s);
                                  setSlaValue(String(s.reply_sla_minutes));
                                }}
                              >
                                Edit SLA
                              </Button>
                              <Button
                                size="small"
                                variant="outlined"
                                disabled={busy === s.id || (!s.is_active && !group)}
                                title={!s.is_active && !group ? "Link a WhatsApp group first" : undefined}
                                onClick={() =>
                                  s.is_active ? setOffTarget(s) : guard(s.id, () => updatePartnerSupplierStatus(s.id, true))
                                }
                              >
                                {s.is_active ? "Deactivate" : "Activate"}
                              </Button>
                            </div>
                          </DataTableCell>
                        </DataTableRow>
                      );
                    }}
                  </DataTableTBody>
                </DataTableTable>
              </DataTableTableContainer>
            </DataTable>
          </div>
          {suppliers.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
              No manual suppliers yet. Add one in Middleware → Suppliers.
            </p>
          )}
        </div>
      )}

      {/* WhatsApp group: link from the shared pending list, unlink, relink */}
      <Dialog open={groupTarget !== null} onOpenChange={(e) => !e.value && setGroupTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup className="w-full max-w-2xl">
              <DialogHeader>
                <DialogTitle>{groupTarget?.name} — WhatsApp Group</DialogTitle>
                <DialogHeaderActions>
                  <DialogClose aria-label="Close">
                    <CloseIcon className="h-5 w-5" />
                  </DialogClose>
                </DialogHeaderActions>
              </DialogHeader>
              <DialogContent>
                <p className="mb-4 text-theme-xs text-gray-500 dark:text-gray-400">
                  One active group per supplier. New orders, the login link and SLA reminders are posted there. To
                  unlink the group of an active supplier, deactivate the supplier first.
                </p>
                {groupTarget && (
                  <WhatsAppGroupLinker
                    key={groupTarget.id}
                    ownerNoun="supplier"
                    loadGroups={() => listPartnerSuppliers().then((list) => list.find((x) => x.id === groupTarget.id)?.whatsapp_groups ?? [])}
                    link={(groupId) => linkSupplierWhatsAppGroup(groupTarget.id, groupId)}
                    setActive={(groupId, isActive) => updateSupplierWhatsAppGroupStatus(groupTarget.id, groupId, isActive)}
                    onChanged={() => void refresh()}
                  />
                )}
              </DialogContent>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>

      {/* Reply SLA */}
      <Dialog open={slaTarget !== null} onOpenChange={(e) => !e.value && setSlaTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Reply SLA — {slaTarget?.name}</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <Label htmlFor="reply_sla_minutes">Minutes before a reminder is posted</Label>
                <Input
                  id="reply_sla_minutes"
                  type="number"
                  min={5}
                  max={1440}
                  value={slaValue}
                  onChange={(e) => setSlaValue(e.target.value)}
                  hint="5 to 1440. The reminder goes out at the next 15-minute check after this."
                />
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setSlaTarget(null)}>Cancel</Button>
                <Button
                  disabled={busy === slaTarget?.id}
                  onClick={async () => {
                    if (!slaTarget) return;
                    if (await guard(slaTarget.id, () => updateSupplierSla(slaTarget.id, Number(slaValue)))) setSlaTarget(null);
                  }}
                >
                  Save
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>

      {/* Deactivate confirm */}
      <Dialog open={offTarget !== null} onOpenChange={(e) => !e.value && setOffTarget(null)}>
        <DialogPortal>
          <DialogBackdrop />
          <DialogPositioner>
            <DialogPopup>
              <DialogHeader>
                <DialogTitle>Deactivate {offTarget?.name}?</DialogTitle>
              </DialogHeader>
              <DialogContent>
                <p className="text-sm text-gray-600 dark:text-gray-300">
                  Its packages go off sale at once. Orders already waiting stay with this supplier, and it can still
                  close them in its group. Activating it again puts back exactly the packages this switched off.
                </p>
              </DialogContent>
              <DialogFooter>
                <Button variant="outlined" onClick={() => setOffTarget(null)}>Cancel</Button>
                <Button
                  severity="danger"
                  disabled={busy === offTarget?.id}
                  onClick={async () => {
                    if (!offTarget) return;
                    if (await guard(offTarget.id, () => updatePartnerSupplierStatus(offTarget.id, false))) setOffTarget(null);
                  }}
                >
                  Deactivate
                </Button>
              </DialogFooter>
            </DialogPopup>
          </DialogPositioner>
        </DialogPortal>
      </Dialog>
    </div>
  );
}
