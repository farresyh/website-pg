/**
 * Admin Panel shell (§6.2-6.18 of docs/prd.md).
 * Accessible to both Admin and Super Admin roles — per-page/action
 * restrictions (e.g. withdrawal approval, maker-checker) are enforced
 * by the Laravel API, not here.
 */
export default function AdminLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen">
      <nav className="w-56 shrink-0 border-r border-black/10 p-4 dark:border-white/15">
        <p className="mb-4 text-sm font-semibold">Admin Panel</p>
        <ul className="space-y-2 text-sm text-black/70 dark:text-white/70">
          <li>Dashboard</li>
          <li>Games &amp; Packages</li>
          <li>Orders</li>
          <li>Withdrawals</li>
          <li>Vouchers</li>
          <li>Reports</li>
        </ul>
      </nav>
      <main className="flex-1 p-6">{children}</main>
    </div>
  );
}
