"use client";

import React, { useState } from "react";
import { useRouter } from "next/navigation";
import {
  Popover,
  PopoverPortal,
  PopoverPositioner,
  PopoverPopup,
  PopoverHeader,
  PopoverContent,
  PopoverClose,
} from "@/components/ui/popover";
import { clearClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";

export default function UserDropdown() {
  const router = useRouter();
  const [isOpen, setIsOpen] = useState(false);
  // A ref callback into state, not useRef().current — reading a ref's
  // .current during render violates react-hooks/refs (the value can be
  // stale/inconsistent there); Popover's `anchor` prop needs the actual
  // element up front, not just a ref object.
  const [triggerEl, setTriggerEl] = useState<HTMLButtonElement | null>(null);
  // sessionStorage doesn't exist during SSR, so a render-body read would
  // render "?"/"Account" on the server and the real name on the client's
  // first (hydration) render — React sees that as a text mismatch. This
  // was the exact cause of the known "+ T / - ?" hydration warning on
  // every admin page load (see docs/prd.md §14). Root-caused during the
  // 2026-07-25 audit; useClientSession() (ADR-038 decision 8) is the
  // hydration-safe fix — see its own doc comment.
  const session = useClientSession();

  function toggleDropdown(e: React.MouseEvent<HTMLButtonElement>) {
    e.stopPropagation();
    setIsOpen((prev) => !prev);
  }

  async function handleLogout() {
    await fetch("/api/logout", {
      method: "POST",
      headers: session ? { Authorization: `Bearer ${session.token}` } : undefined,
    }).catch(() => null);

    clearClientSession();
    router.push("/login");
  }

  return (
    <div className="relative">
      <button ref={setTriggerEl} onClick={toggleDropdown} className="flex items-center text-gray-700 dark:text-gray-400">
        <span className="mr-3 flex h-11 w-11 items-center justify-center rounded-full bg-brand-50 text-sm font-semibold text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
          {session?.name ? session.name.charAt(0).toUpperCase() : "?"}
        </span>
        <span className="block mr-1 font-medium text-theme-sm">{session?.name ?? "Account"}</span>
        <svg
          className={`stroke-gray-500 dark:stroke-gray-400 transition-transform duration-200 ${isOpen ? "rotate-180" : ""}`}
          width="18"
          height="20"
          viewBox="0 0 18 20"
          fill="none"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path d="M4.3125 8.65625L9 13.3437L13.6875 8.65625" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      <Popover open={isOpen} onOpenChange={(e) => setIsOpen(e.value ?? false)} anchor={triggerEl}>
        <PopoverPortal>
          <PopoverPositioner side="bottom" align="end" sideOffset={17}>
            <PopoverPopup className="w-[260px] p-3">
              <PopoverHeader className="border-b border-gray-200 dark:border-gray-800">
                <span className="block font-medium text-gray-700 text-theme-sm dark:text-gray-400">{session?.name}</span>
                <span className="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">{session?.email}</span>
              </PopoverHeader>
              <PopoverContent>
                <ul className="flex flex-col gap-1">
                  <li>
                    <PopoverClose
                      onClick={() => void handleLogout()}
                      className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-theme-sm font-medium text-gray-700 hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300"
                    >
                      Sign out
                    </PopoverClose>
                  </li>
                </ul>
              </PopoverContent>
            </PopoverPopup>
          </PopoverPositioner>
        </PopoverPortal>
      </Popover>
    </div>
  );
}
