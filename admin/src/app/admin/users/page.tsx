"use client";

/**
 * AUTH-4: Super Admin manages admin users (create, edit, activate,
 * deactivate). Role restriction itself is enforced server-side
 * (admin.role:super_admin on every /api/admin-users route) — a regular
 * Admin hitting this page just sees every action fail with a 403 from
 * the API, surfaced as the same error banner as any other failure.
 */

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { Table, TableHeader, TableBody, TableRow, TableCell } from "@/components/ui/table";
import Badge from "@/components/ui/badge/Badge";
import Button from "@/components/ui/button/Button";
import { PlusIcon, PencilIcon } from "@/icons";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import {
  type AdminUser,
  listAdminUsers,
  createAdminUser,
  updateAdminUser,
  updateAdminUserStatus,
} from "@/lib/admin-users";
import AdminUserFormModal, {
  type AdminUserFormSubmitValues,
} from "@/components/admin-users/AdminUserFormModal";

export default function AdminUsersPage() {
  const router = useRouter();
  // Read in an effect, not render body — sessionStorage isn't available
  // during SSR, and reading it directly during render caused the known
  // hydration mismatch on other screens (see UserDropdown.tsx). Fixed
  // here as part of standardizing on this pattern, 2026-07-25 audit.
  const session = useClientSession();

  const [users, setUsers] = useState<AdminUser[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [editingUser, setEditingUser] = useState<AdminUser | null>(null);
  const [statusUpdatingId, setStatusUpdatingId] = useState<number | null>(null);

  async function refreshUsers(token: string) {
    try {
      setUsers(await listAdminUsers(token));
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not load admin users.");
    }
  }

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }

    listAdminUsers(s.token)
      .then(setUsers)
      .catch((err: unknown) => {
        setError(err instanceof ApiError ? err.message : "Could not load admin users.");
      });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  function openCreateModal() {
    setEditingUser(null);
    setIsModalOpen(true);
  }

  function openEditModal(user: AdminUser) {
    setEditingUser(user);
    setIsModalOpen(true);
  }

  async function handleFormSubmit(values: AdminUserFormSubmitValues) {
    if (!session) return;

    if (editingUser) {
      await updateAdminUser(session.token, editingUser.id, values);
    } else {
      await createAdminUser(session.token, values);
    }

    setIsModalOpen(false);
    await refreshUsers(session.token);
  }

  async function handleToggleStatus(user: AdminUser) {
    if (!session) return;
    setError(null);
    setStatusUpdatingId(user.id);

    try {
      await updateAdminUserStatus(session.token, user.id, !user.is_active);
      await refreshUsers(session.token);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : "Could not update status.");
    } finally {
      setStatusUpdatingId(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-xl font-semibold text-gray-800 dark:text-white/90">Admin Users</h1>
          <p className="mt-1 text-sm text-gray-500 dark:text-gray-400">
            Create, edit, activate, or deactivate Super Admin and Admin accounts.
          </p>
        </div>
        <Button size="sm" startIcon={<PlusIcon />} onClick={openCreateModal}>
          Add Admin
        </Button>
      </div>

      {error && (
        <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600 dark:bg-error-500/15 dark:text-error-400">
          {error}
        </p>
      )}

      <div className="overflow-hidden rounded-2xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-white/[0.03]">
        <div className="max-w-full overflow-x-auto">
          <Table>
            <TableHeader className="border-b border-gray-100 dark:border-gray-800">
              <TableRow>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  Name
                </TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  Email
                </TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  Role
                </TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  Status
                </TableCell>
                <TableCell isHeader className="px-5 py-3 text-start text-theme-xs font-medium text-gray-500 dark:text-gray-400">
                  Actions
                </TableCell>
              </TableRow>
            </TableHeader>
            <TableBody className="divide-y divide-gray-100 dark:divide-gray-800">
              {users?.map((user) => {
                const isSelf = user.id === session?.id;
                return (
                  <TableRow key={user.id}>
                    <TableCell className="px-5 py-4 text-theme-sm font-medium text-gray-800 dark:text-white/90">
                      {user.name}
                    </TableCell>
                    <TableCell className="px-5 py-4 text-theme-sm text-gray-500 dark:text-gray-400">
                      {user.email}
                    </TableCell>
                    <TableCell className="px-5 py-4 text-theme-sm">
                      <Badge size="sm" color={user.role === "super_admin" ? "primary" : "light"}>
                        {user.role === "super_admin" ? "Super Admin" : "Admin"}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-5 py-4 text-theme-sm">
                      <Badge size="sm" color={user.is_active ? "success" : "error"}>
                        {user.is_active ? "Active" : "Inactive"}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-5 py-4 text-theme-sm">
                      <div className="flex items-center gap-3">
                        <button
                          onClick={() => openEditModal(user)}
                          aria-label={`Edit ${user.name}`}
                          className="text-gray-500 hover:text-brand-500 dark:text-gray-400"
                        >
                          <PencilIcon className="h-5 w-5" />
                        </button>
                        <Button
                          size="sm"
                          variant={user.is_active ? "danger" : "outline"}
                          disabled={isSelf || statusUpdatingId === user.id}
                          onClick={() => handleToggleStatus(user)}
                        >
                          {isSelf ? "You" : user.is_active ? "Deactivate" : "Activate"}
                        </Button>
                      </div>
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>

          {users?.length === 0 && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">
              No admin users yet.
            </p>
          )}
          {users === null && !error && (
            <p className="p-6 text-center text-sm text-gray-500 dark:text-gray-400">Loading…</p>
          )}
        </div>
      </div>

      <AdminUserFormModal
        isOpen={isModalOpen}
        onClose={() => setIsModalOpen(false)}
        onSubmit={handleFormSubmit}
        editingUser={editingUser}
      />
    </div>
  );
}
