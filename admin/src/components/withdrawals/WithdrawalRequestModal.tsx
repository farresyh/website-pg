"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Button from "@/components/ui/button/Button";
import type { WithdrawalRequestValues } from "@/lib/withdrawals";

interface WithdrawalRequestModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: WithdrawalRequestValues) => Promise<void>;
  availableBalance: number;
}

/**
 * Renders as a child of <Modal>, which unmounts while closed — fresh
 * useState initializers each open, same reasoning as
 * AdminUserFormModal's AdminUserFormFields.
 */
function WithdrawalRequestFields({
  onClose,
  onSubmit,
  availableBalance,
}: Omit<WithdrawalRequestModalProps, "isOpen">) {
  const [amountRm, setAmountRm] = useState("");
  const [bankName, setBankName] = useState("");
  const [bankAccountNo, setBankAccountNo] = useState("");
  const [bankAccountHolder, setBankAccountHolder] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    const amountSen = Math.round(parseFloat(amountRm) * 100);
    if (!Number.isFinite(amountSen) || amountSen <= 0) {
      setError("Enter a valid amount.");
      return;
    }

    setSubmitting(true);
    try {
      await onSubmit({
        amount: amountSen,
        bank_name: bankName,
        bank_account_no: bankAccountNo,
        bank_account_holder: bankAccountHolder,
      });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-1 text-lg font-semibold text-gray-800 dark:text-white/90">
        Request Withdrawal
      </h3>
      <p className="mb-5 text-sm text-gray-500 dark:text-gray-400">
        Available balance: RM {(availableBalance / 100).toFixed(2)}
      </p>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="amount">Amount (RM)</Label>
          <Input
            id="amount"
            type="text"
            value={amountRm}
            onChange={(e) => setAmountRm(e.target.value)}
            placeholder="0.00"
            required
          />
        </div>
        <div>
          <Label htmlFor="bank_name">Bank Name</Label>
          <Input id="bank_name" value={bankName} onChange={(e) => setBankName(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="bank_account_no">Bank Account No.</Label>
          <Input id="bank_account_no" value={bankAccountNo} onChange={(e) => setBankAccountNo(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="bank_account_holder">Account Holder Name</Label>
          <Input id="bank_account_holder" value={bankAccountHolder} onChange={(e) => setBankAccountHolder(e.target.value)} required />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Submitting…" : "Request Withdrawal"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function WithdrawalRequestModal({
  isOpen,
  onClose,
  onSubmit,
  availableBalance,
}: WithdrawalRequestModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && (
        <WithdrawalRequestFields onClose={onClose} onSubmit={onSubmit} availableBalance={availableBalance} />
      )}
    </Modal>
  );
}
