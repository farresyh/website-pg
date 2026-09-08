import Image from "next/image";
import type { PaymentChannel } from "@/lib/payment-methods";

interface PaymentCategory {
  id: string;
  name: string;
  badgeSrc: string;
  description: string;
  categoryKeys: string[];
}

const CHIP_PAYMENT_CATEGORIES: PaymentCategory[] = [
  {
    id: "online-banking",
    name: "Online Banking (FPX)",
    badgeSrc: "/images/chip/online-banking.svg",
    description: "Maybank, CIMB, RHB, Public Bank, Bank Islam & more",
    categoryKeys: ["fpx"],
  },
  {
    id: "duitnow",
    name: "DuitNow QR",
    badgeSrc: "/images/chip/duitnow-qr.svg",
    description: "Scan & pay instantly with any Malaysian banking or e-wallet app",
    categoryKeys: ["duitnow_qr", "dnqr"],
  },
  {
    id: "e-wallets",
    name: "E-Wallets",
    badgeSrc: "/images/chip/e-wallets.svg",
    description: "Touch 'n Go eWallet, GrabPay, ShopeePay & more",
    categoryKeys: ["ewallet", "razer_tng", "razer_grabpay", "shopee_pay"],
  },
  {
    id: "card",
    name: "Debit & Credit Card",
    badgeSrc: "/images/chip/card.svg",
    description: "Secured payment with Visa & Mastercard",
    categoryKeys: ["card", "visa", "mastercard"],
  },
];

export default function PaymentMethodsSection({ channels = [] }: { channels?: PaymentChannel[] }) {
  // If backend returns channels, check active state; FPX is currently active
  const activeCodes = new Set(channels.map((c) => c.channelCode.toLowerCase()));
  const activeCategories = new Set(channels.map((c) => c.category.toLowerCase()));

  return (
    <section className="mx-auto max-w-[1200px] px-4 py-9 lg:py-12">
      <div className="text-center">
        <h2 className="font-display text-headline-md tracking-tight">
          Trusted Payment Methods
        </h2>
        <p className="mt-1.5 text-sm text-on-surface-variant">
          Fast, licensed, and encrypted payments backed by official payment providers
        </p>
      </div>

      <div className="mt-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {CHIP_PAYMENT_CATEGORIES.map((category) => {
          const isActive =
            category.categoryKeys.some((k) => activeCodes.has(k) || activeCategories.has(k)) ||
            (category.id === "online-banking" && (channels.length === 0 || activeCodes.has("fpx")));

          return (
            <div
              key={category.id}
              className="flex flex-col justify-between rounded-lg border-2 border-ink bg-surface-container-lowest p-4 neo transition-all hover:bg-surface-container-low"
            >
              <div>
                <div className="flex h-14 w-full items-center justify-center rounded-md border border-ink/10 bg-white p-2">
                  <Image
                    src={category.badgeSrc}
                    alt={category.name}
                    width={260}
                    height={44}
                    className="h-8 w-auto max-w-full object-contain"
                  />
                </div>
                <div className="mt-3.5">
                  <div className="flex items-center gap-2">
                    <h3 className="font-display text-[14px] font-bold text-on-surface">
                      {category.name}
                    </h3>
                    {isActive ? (
                      <span className="inline-flex items-center rounded-full bg-emerald-500/10 px-2 py-0.5 text-[10px] font-semibold text-emerald-600 dark:text-emerald-400">
                        Active
                      </span>
                    ) : (
                      <span className="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-[10px] font-semibold text-amber-600 dark:text-amber-400">
                        Activating
                      </span>
                    )}
                  </div>
                  <p className="mt-1 text-[12px] leading-relaxed text-on-surface-variant">
                    {category.description}
                  </p>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      <div className="mt-8 flex flex-col items-center justify-center gap-2 border-t-2 border-ink/10 pt-6 text-center sm:flex-row sm:gap-4">
        <div className="flex items-center gap-2">
          <span className="text-[12px] font-semibold text-on-surface-variant">Secured &amp; Powered by</span>
          <Image
            src="/images/chip/powered-by-chip-long.svg"
            alt="Powered by CHIP"
            width={180}
            height={24}
            className="h-5 w-auto object-contain"
          />
        </div>
        <span className="hidden text-on-surface-variant/40 sm:inline">•</span>
        <span className="text-[11.5px] text-on-surface-variant">
          Licensed &amp; compliant with Bank Negara Malaysia standards
        </span>
      </div>
    </section>
  );
}
