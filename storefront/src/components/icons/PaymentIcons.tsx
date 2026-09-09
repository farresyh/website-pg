import type { SVGProps } from "react";
import { Bank, Wallet } from "@phosphor-icons/react/dist/ssr";

export function FpxIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 64 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="FPX" {...props}>
      <rect width="64" height="24" rx="4" fill="#003B70" />
      <path d="M12 6h8v2.5h-5.2v2.2h4.4v2.5h-4.4v4.8H12V6z" fill="#FFFFFF" />
      <path d="M22 6h5.6c2.8 0 4.6 1.4 4.6 3.6 0 2.2-1.8 3.6-4.6 3.6H24.8v4.8H22V6zm2.8 5h2.5c1.4 0 2.2-.6 2.2-1.4 0-.9-.8-1.4-2.2-1.4h-2.5v2.8z" fill="#FFFFFF" />
      <path d="M34 6l3.5 5.8L41 6h3.2l-5.1 7.8 5.4 8.2h-3.3l-3.8-6.1-3.8 6.1h-3.3l5.4-8.2L30.8 6H34z" fill="#F37023" />
      <path d="M47 8.5h9v2h-9zM47 13.5h7v2h-7z" fill="#FFFFFF" />
    </svg>
  );
}

export function TouchNGoIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 64 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Touch 'n Go eWallet" {...props}>
      <rect width="64" height="24" rx="4" fill="#01509F" />
      <text x="32" y="16.5" fill="#FFFFFF" fontFamily="sans-serif" fontWeight="900" fontSize="12" textAnchor="middle" letterSpacing="-0.5">
        TNG
      </text>
      <circle cx="53" cy="12" r="4.5" fill="#FDB813" />
    </svg>
  );
}

export function DuitNowIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 72 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="DuitNow QR" {...props}>
      <rect width="72" height="24" rx="4" fill="#ED0080" />
      <g transform="translate(6, 4) scale(0.14)">
        <path d="m63.8742 0c-22.1519 0-40.1096 17.9577-40.1096 40.1097v40.1095h40.1096c22.1521 0 40.1098-17.9576 40.1098-40.1095 0-22.152-17.9577-40.1097-40.1098-40.1097z" fill="#FFFFFF" />
        <path d="m63.8753 17.9219c-12.2544 0-22.1888 9.9343-22.1888 22.1888v22.1889h22.1888c12.2549 0 22.1891-9.9342 22.1891-22.1889 0-12.2545-9.9342-22.1888-22.1891-22.1888zm0 32.1003h-9.9112v-9.9115c0-5.4739 4.4374-9.9114 9.9112-9.9114 5.4743 0 9.9117 4.4375 9.9117 9.9114 0 5.4741-4.4374 9.9115-9.9117 9.9115z" fill="#ED0080" />
      </g>
      <text x="44" y="16.5" fill="#FFFFFF" fontFamily="sans-serif" fontWeight="900" fontSize="10.5" textAnchor="middle">
        DuitNow
      </text>
    </svg>
  );
}

export function GrabPayIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 64 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="GrabPay" {...props}>
      <rect width="64" height="24" rx="4" fill="#00B14F" />
      <text x="32" y="16.5" fill="#FFFFFF" fontFamily="sans-serif" fontWeight="800" fontSize="11" textAnchor="middle">
        GrabPay
      </text>
    </svg>
  );
}

export function CardPaymentIcon(props: SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 64 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-label="Visa / Mastercard" {...props}>
      <rect width="64" height="24" rx="4" fill="#1A1F71" />
      <circle cx="27" cy="12" r="6" fill="#EB001B" fillOpacity="0.9" />
      <circle cx="37" cy="12" r="6" fill="#F79E1B" fillOpacity="0.9" />
    </svg>
  );
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
  const code = (channelCode ?? "").toLowerCase();
  const cat = (category ?? "").toLowerCase();

  if (code.includes("fpx") || cat === "fpx") {
    return <FpxIcon className={className} />;
  }
  if (code.includes("touchngo") || code.includes("tng")) {
    return <TouchNGoIcon className={className} />;
  }
  if (code.includes("duitnow") || cat.includes("duitnow") || cat === "dnqr") {
    return <DuitNowIcon className={className} />;
  }
  if (code.includes("grab")) {
    return <GrabPayIcon className={className} />;
  }
  if (code.includes("card") || cat === "card") {
    return <CardPaymentIcon className={className} />;
  }
  if (cat === "ewallet") {
    return <Wallet size={20} className="text-secondary" weight="fill" />;
  }
  return <Bank size={20} className="text-primary" weight="fill" />;
}
