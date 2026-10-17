import type { Metadata } from "next";
import localFont from "next/font/local";
import "./globals.css";
import { ThemeProvider } from "@/context/ThemeContext";
import { PrimeProvider } from "@/components/prime-provider";

// PRD §16 item 109: committed latin woff2 files, so no build fetches Google Fonts.
const outfit = localFont({ src: "./fonts/outfit-latin.woff2", weight: "100 900" });

export const metadata: Metadata = {
  title: "PekanGame — Partner Portal",
  description: "Orders and account tools for PekanGame affiliate and reseller partners.",
};

export default function RootLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return (
    <html lang="en">
      <body className={`${outfit.className} dark:bg-gray-900`}>
        <PrimeProvider>
          <ThemeProvider>{children}</ThemeProvider>
        </PrimeProvider>
      </body>
    </html>
  );
}
