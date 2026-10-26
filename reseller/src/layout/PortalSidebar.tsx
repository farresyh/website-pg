"use client";

import React from "react";
import Link from "next/link";
import { useSidebar } from "@/context/SidebarContext";
import { PortalIcon } from "@/components/shell/PortalIcon";
import { useSupportContacts, whatsAppLink } from "@/hooks/useSupportContacts";
import { isActivePath, type PortalNavGroup, type PortalNavItem } from "@/lib/portal-nav";
import { formatMalaysianPhone } from "@/lib/format";

/**
 * ADR-128 shell sidebar. Desktop (lg+): fixed, 248px, grouped nav, the
 * partner's account block in the nude accent, support contacts at the foot.
 * Phone: the same panel is the ADR-112 "More" drawer, opened from the bottom
 * bar, holding the destinations the bar doesn't plus account controls.
 */
export default function PortalSidebar({
  groups,
  moreItems,
  pathname,
  accountName,
  roleLabel,
  phoneFooter,
}: {
  groups: PortalNavGroup[];
  moreItems: PortalNavItem[];
  pathname: string;
  accountName: string;
  roleLabel: string;
  phoneFooter: React.ReactNode;
}) {
  const { isMobileOpen, closeMobileSidebar } = useSidebar();
  const closeButtonRef = React.useRef<HTMLButtonElement>(null);

  const closeAndRestoreFocus = React.useCallback(() => {
    closeMobileSidebar();
    document.getElementById("portal-more-trigger")?.focus();
  }, [closeMobileSidebar]);

  React.useEffect(() => {
    if (!isMobileOpen) return;
    closeButtonRef.current?.focus();

    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === "Escape") {
        closeAndRestoreFocus();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = document
        .getElementById("portal-more-panel")
        ?.querySelectorAll<HTMLElement>("a[href], button:not([disabled])");
      const visible = [...(focusable ?? [])].filter((el) => el.offsetParent !== null);
      if (!visible.length) return;
      const first = visible[0];
      const last = visible[visible.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }

    window.addEventListener("keydown", handleKeyDown);
    return () => window.removeEventListener("keydown", handleKeyDown);
  }, [isMobileOpen, closeAndRestoreFocus]);

  const initials = accountName
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((word) => word[0]!.toUpperCase())
    .join("");

  return (
    <aside
      id="portal-more-panel"
      aria-label={isMobileOpen ? "More navigation" : "Portal navigation"}
      className={`fixed inset-y-0 left-0 z-50 flex w-[var(--width-sidebar)] max-w-[85vw] flex-col border-r border-border bg-surface transition-transform duration-200 lg:visible lg:translate-x-0 ${
        isMobileOpen ? "visible translate-x-0" : "invisible -translate-x-full"
      }`}
    >
      <div className="flex h-[var(--height-topbar)] shrink-0 items-center gap-2.5 border-b border-border px-5">
        <Link href="/dashboard" onClick={closeMobileSidebar} className="flex items-center gap-2.5 rounded-md">
          <span className="grid h-[30px] w-[30px] place-items-center rounded-md bg-ink text-[13px] font-bold tracking-tight text-surface">PG</span>
          <span className="text-[15px] font-bold leading-[18px] tracking-tight text-ink">
            Pekan<span className="text-cyan-600">Game</span>
          </span>
        </Link>
        <span className="ml-auto rounded bg-nude-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-[0.06em] text-nude-ink">Partner</span>
        <button
          ref={closeButtonRef}
          type="button"
          onClick={closeAndRestoreFocus}
          aria-label="Close more navigation"
          className="grid h-11 w-11 place-items-center rounded-md text-ink-muted hover:bg-overlay hover:text-ink lg:hidden"
        >
          <PortalIcon name="close" />
        </button>
      </div>

      <div className="mx-3 mt-3 flex items-center gap-2.5 rounded-lg bg-nude-100 px-3 py-2.5">
        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-full bg-surface text-xs font-bold text-nude-ink">{initials || "PG"}</span>
        <span className="min-w-0">
          <span className="block truncate text-[13px] font-semibold leading-[18px] text-ink">{accountName}</span>
          <span className="block text-[11px] font-semibold uppercase leading-4 tracking-[0.04em] text-nude-ink">{roleLabel} account</span>
        </span>
      </div>

      <nav aria-label="Main" className="no-scrollbar hidden min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-3 py-2 lg:flex">
        {groups.map((group) => (
          <div key={group.label} className="pt-3">
            <p className="px-2.5 pb-1.5 text-[11px] font-semibold uppercase leading-4 tracking-[0.06em] text-ink-subtle">{group.label}</p>
            <ul className="flex flex-col gap-0.5">
              {group.items.map((item) => (
                <NavLink key={item.href} item={item} active={isActivePath(pathname, item.href)} onNavigate={closeMobileSidebar} />
              ))}
            </ul>
          </div>
        ))}
      </nav>

      <nav aria-label="More" className="no-scrollbar flex min-h-0 flex-1 flex-col overflow-y-auto px-3 py-3 lg:hidden">
        <ul className="flex flex-col gap-0.5">
          {moreItems.map((item) => (
            <NavLink key={item.href} item={item} active={isActivePath(pathname, item.href)} onNavigate={closeMobileSidebar} />
          ))}
        </ul>
      </nav>

      <SupportBox />
      <div className="shrink-0 border-t border-border px-4 pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] lg:hidden">{phoneFooter}</div>
    </aside>
  );
}

function NavLink({ item, active, onNavigate }: { item: PortalNavItem; active: boolean; onNavigate: () => void }) {
  return (
    <li>
      <Link
        href={item.href}
        onClick={onNavigate}
        aria-current={active ? "page" : undefined}
        className={`relative flex min-h-11 items-center gap-2.5 rounded-md px-2.5 text-[13px] font-medium lg:min-h-9 ${
          active
            ? "bg-cyan-50 font-semibold text-cyan-ink before:absolute before:-left-3 before:top-1.5 before:bottom-1.5 before:w-[3px] before:rounded-r before:bg-cyan-600"
            : "text-ink-muted hover:bg-overlay hover:text-ink"
        }`}
      >
        <span className={active ? "text-cyan-ink" : "text-ink-subtle"}>
          <PortalIcon name={item.icon} />
        </span>
        {item.name}
      </Link>
    </li>
  );
}

function SupportBox() {
  const contacts = useSupportContacts();
  if (!contacts?.phone && !contacts?.email) return null;

  return (
    <div className="m-3 shrink-0 rounded-lg border border-border p-3 text-xs leading-4 text-ink-subtle">
      <p className="mb-1.5 text-[13px] font-semibold text-ink">Need help?</p>
      {contacts.phone && (
        <a href={whatsAppLink(contacts.phone)} target="_blank" rel="noreferrer" className="flex min-h-7 items-center gap-1.5 font-medium text-cyan-ink hover:underline">
          <PortalIcon name="chat" className="h-4 w-4" />
          {formatMalaysianPhone(contacts.phone)}
        </a>
      )}
      {contacts.email && (
        <a href={`mailto:${contacts.email}`} className="flex min-h-7 items-center gap-1.5 font-medium text-cyan-ink hover:underline">
          <PortalIcon name="mail" className="h-4 w-4" />
          <span className="truncate">{contacts.email}</span>
        </a>
      )}
      <p className="mt-1.5">WhatsApp or email, quote your order number.</p>
    </div>
  );
}
