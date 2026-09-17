import type { Metadata } from "next";
import { Figtree } from "next/font/google";
import "./globals.css";
import { ThemeProvider } from "@/context/ThemeContext";
import { SidebarProvider } from "@/context/SidebarContext";
import { PrimeProvider } from "@/components/prime-provider";

// ADR-104: Outfit -> Figtree
const figtree = Figtree({ subsets: ["latin"] });

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
          <ThemeProvider>
            <SidebarProvider>{children}</SidebarProvider>
          </ThemeProvider>
        </PrimeProvider>
      </body>
    </html>
  );
}
