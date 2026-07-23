/**
 * Middleware Panel shell (§6.19-6.21 of docs/prd.md) — Supplier Middleware
 * business panel (product matching, price sync, request logs, developer API
 * tester with raw supplier credentials). This is a SUPER-ADMIN-ONLY area
 * per §3 Users & Roles ("Admin ... Cannot modify system settings, supplier
 * credentials, or blacklist rules").
 *
 * Note: the "/middleware" URL segment here refers to this business concept
 * (Supplier Middleware), unrelated to Next.js's proxy/middleware request
 * mechanism (src/proxy.ts) — coincidental naming overlap only.
 *
 * TODO: this layout currently only has the optimistic session check from
 * proxy.ts. Once Laravel exposes the caller's role, add a role check here
 * (still optimistic/UX-only — Laravel remains the real enforcement layer)
 * to redirect non-Super-Admins away from this section.
 */
export default function MiddlewareLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  return (
    <div className="flex min-h-screen">
      <nav className="w-56 shrink-0 border-r border-black/10 p-4 dark:border-white/15">
        <p className="mb-4 text-sm font-semibold">Middleware Panel</p>
        <ul className="space-y-2 text-sm text-black/70 dark:text-white/70">
          <li>Dashboard</li>
          <li>Product Manager</li>
          <li>Price Sync</li>
          <li>Validate Player</li>
          <li>Request Logs</li>
          <li>Developer / API Tester</li>
        </ul>
      </nav>
      <main className="flex-1 p-6">{children}</main>
    </div>
  );
}
