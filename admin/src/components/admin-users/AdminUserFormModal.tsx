"use client";

import React, { useState } from "react";
import { Modal } from "@/components/ui/modal";
import Label from "@/components/form/Label";
import Input from "@/components/form/input/InputField";
import Select from "@/components/form/Select";
import Button from "@/components/ui/button/Button";
import type { AdminUser } from "@/lib/admin-users";

export interface AdminUserFormSubmitValues {
  name: string;
  email: string;
  password: string;
  role: "super_admin" | "admin";
  phone: string;
}

interface AdminUserFormModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSubmit: (values: AdminUserFormSubmitValues) => Promise<void>;
  editingUser: AdminUser | null;
}

const ROLE_OPTIONS = [
  { value: "admin", label: "Admin" },
  { value: "super_admin", label: "Super Admin" },
];

/**
 * Renders as a child of <Modal>, which returns null while closed — so
 * this fully unmounts on close and remounts fresh on the next open,
 * giving each open a clean set of useState initializers instead of
 * needing an effect to resync fields when `editingUser` changes.
 */
function AdminUserFormFields({
  onClose,
  onSubmit,
  editingUser,
}: Omit<AdminUserFormModalProps, "isOpen">) {
  const isEditing = editingUser !== null;

  const [name, setName] = useState(editingUser?.name ?? "");
  const [email, setEmail] = useState(editingUser?.email ?? "");
  const [password, setPassword] = useState("");
  const [role, setRole] = useState<"super_admin" | "admin">(editingUser?.role ?? "admin");
  const [phone, setPhone] = useState(editingUser?.phone ?? "");
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setSubmitting(true);

    try {
      await onSubmit({ name, email, password, role, phone });
    } catch (err) {
      setError(err instanceof Error ? err.message : "Something went wrong.");
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="max-w-md p-6">
      <h3 className="mb-5 text-lg font-semibold text-gray-800 dark:text-white/90">
        {isEditing ? "Edit Admin User" : "Add Admin User"}
      </h3>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-3 py-2 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <form onSubmit={handleSubmit} className="space-y-4">
        <div>
          <Label htmlFor="name">Name</Label>
          <Input id="name" value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="email">Email</Label>
          <Input id="email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
        </div>
        <div>
          <Label htmlFor="password">{isEditing ? "New Password" : "Password"}</Label>
          <Input
            id="password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required={!isEditing}
            hint={isEditing ? "Leave blank to keep the current password." : "Minimum 8 characters."}
          />
        </div>
        <div>
          <Label htmlFor="role">Role</Label>
          <Select options={ROLE_OPTIONS} value={role} onChange={(v) => setRole(v as "super_admin" | "admin")} />
        </div>
        <div>
          <Label htmlFor="phone">Phone (optional)</Label>
          <Input id="phone" type="tel" value={phone} onChange={(e) => setPhone(e.target.value)} />
        </div>

        <div className="flex items-center justify-end gap-3 pt-2">
          <Button type="button" variant="outline" onClick={onClose} disabled={submitting}>
            Cancel
          </Button>
          <Button type="submit" disabled={submitting}>
            {submitting ? "Saving…" : isEditing ? "Save Changes" : "Create Admin"}
          </Button>
        </div>
      </form>
    </div>
  );
}

export default function AdminUserFormModal({
  isOpen,
  onClose,
  onSubmit,
  editingUser,
}: AdminUserFormModalProps) {
  return (
    <Modal isOpen={isOpen} onClose={onClose} className="max-w-md">
      {isOpen && (
        <AdminUserFormFields
          key={editingUser?.id ?? "new"}
          onClose={onClose}
          onSubmit={onSubmit}
          editingUser={editingUser}
        />
      )}
    </Modal>
  );
}
