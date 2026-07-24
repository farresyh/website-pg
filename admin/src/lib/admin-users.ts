import { apiFetch } from "@/lib/api-client";

export interface AdminUser {
  id: number;
  name: string;
  email: string;
  role: "super_admin" | "admin";
  phone: string | null;
  is_active: boolean;
}

export interface AdminUserFormValues {
  name: string;
  email: string;
  password: string;
  role: "super_admin" | "admin";
  phone: string;
}

export function listAdminUsers(token: string) {
  return apiFetch<AdminUser[]>("/api/admin-users", { token });
}

export function createAdminUser(token: string, values: AdminUserFormValues) {
  return apiFetch<AdminUser>("/api/admin-users", {
    method: "POST",
    token,
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
  token: string,
  id: number,
  values: Omit<AdminUserFormValues, "password"> & { password?: string },
) {
  return apiFetch<AdminUser>(`/api/admin-users/${id}`, {
    method: "PUT",
    token,
    body: {
      name: values.name,
      email: values.email,
      role: values.role,
      phone: values.phone || null,
      ...(values.password ? { password: values.password } : {}),
    },
  });
}

export function updateAdminUserStatus(token: string, id: number, isActive: boolean) {
  return apiFetch<AdminUser>(`/api/admin-users/${id}/status`, {
    method: "PATCH",
    token,
    body: { is_active: isActive },
  });
}
