import type { Metadata } from "next";
import { Outfit } from "next/font/google";
import "./globals.css";
import { ThemeProvider } from "@/context/ThemeContext";
import { PrimeProvider } from "@/components/prime-provider";

const outfit = Outfit({ subsets: ["latin"] });

export const metadata: Metadata = {
  title: "Kedai Runcit Soloz — Reseller Portal",
  description: "Earnings, orders, and storefront settings for reseller partners.",
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
