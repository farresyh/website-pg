import type { Metadata } from "next";
import localFont from "next/font/local";
import "./globals.css";
import { ThemeProvider } from "@/context/ThemeContext";
import { SidebarProvider } from "@/context/SidebarContext";
import { PrimeProvider } from "@/components/prime-provider";
import { ToastProvider } from "@/components/ui/toast";

// ADR-104: Outfit -> Figtree
// PRD §16 item 109: committed latin woff2 files, so no build fetches Google Fonts.
const figtree = localFont({ src: "./fonts/figtree-latin.woff2", weight: "300 900" });

export const metadata: Metadata = {
  title: "PekanGame — Admin",
  description: "Admin & Middleware panel for the game top-up platform.",
};

export default function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  return (
    <html lang="en">
      <body className={`${figtree.className} dark:bg-gray-900`}>
        <PrimeProvider>
          <ToastProvider>
            <ThemeProvider>
              <SidebarProvider>{children}</SidebarProvider>
            </ThemeProvider>
          </ToastProvider>
        </PrimeProvider>
      </body>
    </html>
  );
}
