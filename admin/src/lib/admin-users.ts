import { apiFetch } from "@/lib/api-client";
import type { MfaStatus } from "@/lib/auth";

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: "super_admin" | "admin";
  phone: string | null;
  is_active: boolean;
  mfa_status: MfaStatus;
}

export interface AdminUserFormValues {
  name: string;
  email: string;
  password: string;
  role: "super_admin" | "admin";
  phone: string;
}

export function listAdminUsers() {
  return apiFetch<AdminUser[]>("/api/admin-users");
}

export function createAdminUser(values: AdminUserFormValues) {
  return apiFetch<AdminUser>("/api/admin-users", {
    method: "POST",
    body: {
      name: values.name,
      email: values.email,
      password: values.password,
      role: values.role,
      phone: values.phone || null,
    },
  });
}

export function updateAdminUser(
  id: number,
  values: Omit<AdminUserFormValues, "password"> & { password?: string },
) {
  return apiFetch<AdminUser>(`/api/admin-users/${id}`, {
    method: "PUT",
    body: {
      name: values.name,
      email: values.email,
      role: values.role,
      phone: values.phone || null,
      ...(values.password ? { password: values.password } : {}),
    },
  });
}

export function updateAdminUserStatus(id: number, isActive: boolean) {
  return apiFetch<AdminUser>(`/api/admin-users/${id}/status`, {
    method: "PATCH",
    body: { is_active: isActive },
  });
}

/** ADR-121: one-way; the account sets MFA up at its next login. Turning it off is SSH-only. */
export function requireAdminUserMfa(id: number) {
  return apiFetch<AdminUser>(`/api/admin-users/${id}/require-mfa`, { method: "PATCH" });
}
