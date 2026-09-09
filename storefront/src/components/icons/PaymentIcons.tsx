import Image from "next/image";
import { Bank, Wallet } from "@phosphor-icons/react/dist/ssr";

/**
 * Real, brand-official payment marks for the checkout / membership
 * payment-method pickers. FPX and DuitNow QR are PayNet's own artwork
 * (`public/images/payments/`); Cards and e-Wallets fall back to CHIP's
 * category lockups until individual providers (TnG, GrabPay, …) are
 * activated and get their own marks. The old hand-drawn placeholder SVGs
 * were replaced 2026-09-10 — see `docs/adr.md` ADR-079 follow-up.
 */
type Mark = { src: string; width: number; height: number; alt: string };

const MARKS: Record<"fpx" | "duitnow" | "card" | "ewallet", Mark> = {
  fpx: { src: "/images/payments/fpx.png", width: 1300, height: 710, alt: "FPX — Pay with Online Banking" },
  duitnow: { src: "/images/payments/duitnow-qr.svg", width: 165, height: 173, alt: "DuitNow QR" },
  card: { src: "/images/payments/cards.svg", width: 671, height: 113, alt: "Debit & credit card" },
  ewallet: { src: "/images/payments/ewallets.svg", width: 734, height: 113, alt: "e-Wallet" },
};

function resolveMark(code: string, cat: string): Mark | null {
  if (code.includes("fpx") || cat === "fpx") return MARKS.fpx;
  if (code.includes("duitnow") || cat.includes("duitnow") || cat === "dnqr") return MARKS.duitnow;
  if (code.includes("card") || cat === "card") return MARKS.card;
  // A named single e-wallet (touchngo/grabpay/boost/…) has no brand mark
  // of its own yet — the generic CHIP e-wallet lockup stands in.
  if (cat === "ewallet" || code.includes("tng") || code.includes("touchngo") || code.includes("grab") || code.includes("boost")) {
    return MARKS.ewallet;
  }
  return null;
}

export function PaymentChannelIcon({
  channelCode,
  category,
  className = "h-5 w-auto",
}: {
  channelCode?: string;
  category?: string;
  className?: string;
}) {
  const mark = resolveMark((channelCode ?? "").toLowerCase(), (category ?? "").toLowerCase());

  if (mark) {
    return (
      <Image
        src={mark.src}
        alt={mark.alt}
        width={mark.width}
        height={mark.height}
        className={`${className} max-w-[140px] object-contain`}
      />
    );
  }

  // Unknown method — a neutral glyph, never a fabricated brand mark.
  if ((category ?? "").toLowerCase() === "ewallet") {
    return <Wallet size={20} className="text-secondary" weight="fill" />;
  }
  return <Bank size={20} className="text-primary" weight="fill" />;
}
