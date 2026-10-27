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
