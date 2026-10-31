"use client";

import Link from "next/link";
import { useClientSession } from "@/hooks/useClientSession";
import { useOnboarding } from "@/hooks/useOnboarding";
import { banners } from "@/lib/onboarding";

/**
 * ADR-133 decision 15: the banners that cannot be dismissed, above every
 * screen, only for what costs money or stops selling.
 */
export default function PartnerBanners() {
  const session = useClientSession();
  const ownerType = session?.owner_type;
  const onboarding = useOnboarding(ownerType);

  if (!ownerType || !onboarding) return null;
  const list = banners(ownerType, onboarding);
  if (list.length === 0) return null;

  return (
    <div className="flex flex-col">
      {list.map((b) => (
        <div
          key={b.key}
          role="status"
          className={`flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-b px-4 py-2.5 text-sm lg:px-8 ${
            b.tone === "warning" ? "border-warning-ink/30 bg-warning-surface text-warning-ink" : "border-info-ink/30 bg-info-surface text-info-ink"
          }`}
        >
          <span>
            <strong>{b.title}.</strong> {b.text}
          </span>
          {b.href && b.cta && (
            <Link href={b.href} className="font-semibold underline underline-offset-2">
              {b.cta}
            </Link>
          )}
        </div>
      ))}
    </div>
  );
}
