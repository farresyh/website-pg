/**
 * Static placeholder content for the homepage. Games/packages moved to
 * lib/catalog.ts (real backend data, task #6) — everything remaining
 * here (payment channels, hero slides, promotions, testimonials, FAQ)
 * still has no backing model, per the same "honest static content, not
 * a fake dynamic system" reasoning documented on PROMOTIONS below.
 */
export interface PaymentChannel {
  /** Real Xendit Malaysia channel codes (database/seeders/PaymentMethodSeeder.php) — accurate today even though the checkout call itself will 422 until an admin activates a matching row. */
  channelCode: string;
  label: string;
  category: "fpx" | "ewallet" | "card";
}

export const PLACEHOLDER_PAYMENT_CHANNELS: PaymentChannel[] = [
  { channelCode: "MAYB2U_FPX", label: "Maybank2U", category: "fpx" },
  { channelCode: "CIMB_FPX", label: "CIMB Bank", category: "fpx" },
  { channelCode: "PUBLIC_FPX", label: "Public Bank", category: "fpx" },
  { channelCode: "TOUCHNGO", label: "Touch 'n Go eWallet", category: "ewallet" },
  { channelCode: "GRABPAY", label: "GrabPay", category: "ewallet" },
  { channelCode: "SHOPEEPAY", label: "ShopeePay", category: "ewallet" },
  { channelCode: "CARDS", label: "Card (Visa, Mastercard, etc.)", category: "card" },
];

/**
 * Hero slides use CSS gradients/diagonal shapes instead of background
 * images — no real campaign key-art files exist yet, and the original
 * draft's hotlinked Figma asset URLs (which expire ~7 days) are
 * exactly the kind of thing we're not repeating here.
 */
export interface HeroSlide {
  eyebrow: string;
  title: string;
  description: string;
  priceFromRm?: number;
  primaryCta: { label: string; href: string };
  secondaryCta: { label: string; href: string };
}

export const HERO_SLIDES: HeroSlide[] = [
  {
    eyebrow: "Top Up Made Easy",
    title: "Top up your favorite games in seconds",
    description: "Pick your game, place your order, and pay your way — the fastest top-up experience in Malaysia.",
    primaryCta: { label: "Find Games", href: "#popular-picks" },
    secondaryCta: { label: "Track Order", href: "/track-order" },
  },
  {
    eyebrow: "Limited Offer",
    title: "PUBG Mobile — Bonus UC Weekend",
    description: "Get extra UC on every top-up this weekend only. Instant delivery, no waiting.",
    priceFromRm: 4.0,
    primaryCta: { label: "Top Up Now", href: "/order/pubg-mobile" },
    secondaryCta: { label: "View Details", href: "#popular-picks" },
  },
  {
    eyebrow: "Weekly Deal",
    title: "15% Off Steam Wallet Codes",
    description: "Stock up on Steam Wallet credit this week and save on your next purchase.",
    priceFromRm: 5.0,
    primaryCta: { label: "Shop Steam Wallet", href: "/order/steam-wallet" },
    secondaryCta: { label: "See All Deals", href: "#promotions" },
  },
];

/**
 * Manually curated for v1 — no Promotion model/admin screen exists
 * yet (confirmed during the storefront planning audit), so this is
 * honest static content, not a fake "dynamic" system pretending to
 * read from a backend that isn't there.
 */
export interface Promotion {
  id: string;
  badge: string;
  endsAt: string;
  title: string;
  description: string;
}

export const PROMOTIONS: Promotion[] = [
  {
    id: "mlbb-bonus-diamonds",
    badge: "Sunday Only",
    endsAt: "Jul 28, 2026",
    title: "10% Bonus Diamonds — MLBB",
    description: "All-day Sunday offer — get free bonus diamonds on every 516 Diamonds pack.",
  },
  {
    id: "steam-wallet-discount",
    badge: "Limited",
    endsAt: "Jul 31, 2026",
    title: "15% Off Steam Wallet",
    description: "Get 15% off your first Steam Wallet purchase this week.",
  },
  {
    id: "tng-cashback",
    badge: "Limited",
    endsAt: "Jul 31, 2026",
    title: "RM3 Cashback via Touch 'n Go",
    description: "Cashback to your Touch 'n Go eWallet for your first transaction this week.",
  },
];

export interface Testimonial {
  name: string;
  rating: number;
  quote: string;
}

export const TESTIMONIALS: Testimonial[] = [
  { name: "Ahmad R.", rating: 5, quote: "Fast process — diamonds arrived within 2 minutes of payment." },
  { name: "Nurul S.", rating: 5, quote: "Very easy and safe. Only the first-time setup takes a bit of time." },
  { name: "Hafiz M.", rating: 5, quote: "Cheapest UC prices in Malaysia with instant delivery. Best, Soloz!" },
];

export interface FaqItem {
  question: string;
  answer: string;
}

export const FAQ_ITEMS: FaqItem[] = [
  {
    question: "How long does the top-up process take?",
    answer: "Most orders are processed automatically within 1–3 minutes after payment is confirmed by our system.",
  },
  {
    question: "How do I check my order status?",
    answer: 'Use the "Track Order" menu at the top of the site and enter your order number.',
  },
  {
    question: "What should I do if I entered the wrong ID?",
    answer:
      "Contact our WhatsApp customer support immediately with your order number. Successful delivery to a wrong ID cannot be reversed.",
  },
  {
    question: "Is my payment secure?",
    answer: "Yes. All transactions are processed through licensed FPX and e-wallet networks with SSL encryption.",
  },
  {
    question: "How do I contact support?",
    answer: "Reach our customer support team directly via WhatsApp for a manual check if your status is delayed beyond 10 minutes.",
  },
];

export const PAYMENT_METHODS = ["Online Banking (FPX)", "e-Wallet (TnG, Grab, Shopee)", "GrabPay QR", "Card Payment (Visa, Master)"];
