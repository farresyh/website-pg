import type { Metadata } from "next";
import ApplicationShell from "@/components/application/ApplicationShell";

// ADR-133 decision 4: never indexed (`proxy.ts` also sends X-Robots-Tag on this host).
export const metadata: Metadata = {
  title: "Become a PekanGame reseller",
  description: "Apply to sell game top-ups as a PekanGame reseller.",
  robots: { index: false, follow: false },
};

export default function OnboardingLayout({ children }: { children: React.ReactNode }) {
  return <ApplicationShell>{children}</ApplicationShell>;
}
