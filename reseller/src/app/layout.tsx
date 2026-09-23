import type { Metadata } from "next";
import { Outfit } from "next/font/google";
import "./globals.css";
import { ThemeProvider } from "@/context/ThemeContext";
import { PrimeProvider } from "@/components/prime-provider";

const outfit = Outfit({ subsets: ["latin"] });

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
