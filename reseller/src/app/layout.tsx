import type { Metadata } from "next";
import localFont from "next/font/local";
import "./globals.css";
import { ThemeProvider } from "@/context/ThemeContext";
import { PrimeProvider } from "@/components/prime-provider";
import { ToastProvider } from "@/components/ui/toast";

// ADR-128: Figtree, as the admin (ADR-104). PRD §16 item 109: a committed
// latin woff2 file, so no build fetches Google Fonts.
const figtree = localFont({ src: "./fonts/figtree-latin.woff2", weight: "300 900" });

export const metadata: Metadata = {
  title: "PekanGame Partner",
  description: "Orders and account tools for PekanGame affiliate and reseller partners.",
  // PRD §16 item 153 (founder, 2026-11-01): the portal is never in search results.
  // Applicants arrive through /apply on the onboarding host, never a portal link.
  robots: { index: false, follow: false },
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body className={figtree.className}>
        <PrimeProvider>
          <ToastProvider>
            <ThemeProvider>{children}</ThemeProvider>
          </ToastProvider>
        </PrimeProvider>
      </body>
    </html>
  );
}
