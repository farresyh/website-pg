import Image from "next/image";

/**
 * The official mark for a payment method: the storefront's own artwork,
 * synced into public/images (scripts/sync-shared-files.mjs). Same matching
 * as the storefront's PaymentChannelIcon; null when there is no mark.
 */
const MARKS = {
  fpx: { src: "/images/payments/fpx.png", width: 1300, height: 710, alt: "FPX" },
  duitnow: { src: "/images/payments/duitnow-qr.svg", width: 165, height: 173, alt: "DuitNow QR" },
  card: { src: "/images/payments/cards.svg", width: 671, height: 113, alt: "Debit and credit card" },
  ewallet: { src: "/images/payments/ewallets.svg", width: 734, height: 113, alt: "e-Wallet" },
};

function resolve(code: string, category: string) {
  if (code.includes("fpx") || category === "fpx") return MARKS.fpx;
  if (code.includes("duitnow") || category.includes("duitnow") || category === "dnqr") return MARKS.duitnow;
  if (code.includes("card") || category === "card") return MARKS.card;
  if (category === "ewallet") return MARKS.ewallet;
  return null;
}

export function PaymentMark({ channelCode, category }: { channelCode: string; category: string }) {
  const mark = resolve(channelCode, category);
  if (!mark) return null;
  return (
    <span className="grid h-8 w-12 shrink-0 place-items-center rounded-sm border border-border bg-white px-1">
      <Image src={mark.src} alt={mark.alt} width={mark.width} height={mark.height} className="h-5 w-auto object-contain" />
    </span>
  );
}

/** CHIP's own "Powered by CHIP" lockup, on white so it reads in both themes. */
export function PoweredByChip() {
  return (
    <span className="inline-flex items-center rounded-sm bg-white px-1.5 py-1">
      <Image src="/images/chip/powered-by-chip-long.svg" alt="Powered by CHIP" width={130} height={18} className="h-4 w-auto" />
    </span>
  );
}
