/**
 * Static placeholder content for the homepage — the public catalog
 * endpoint (GET /api/games public variant) doesn't exist yet, see
 * docs/prd.md's storefront implementation status. Shaped to match the
 * real `games`/`packages` fields (slug, name, category, image_url —
 * nullable, same as the real Game model) so swapping this file for a
 * real fetch later is a data-source change, not a component rewrite.
 *
 * `priceFromRm` is a display placeholder in ringgit — the real
 * storefront will only ever show a server-computed price (ORD-9),
 * never a client-side number like this.
 */
export interface PlaceholderGame {
  /**
   * Fabricated, sequential — won't resolve against the real backend
   * (CheckoutController/PlayerValidationController both 404/422 on an
   * unknown game_id) until task #6 (public catalog endpoint) replaces
   * this whole data source. The request/response wiring in
   * lib/checkout.ts is real regardless — only the source of `id` is
   * placeholder.
   */
  id: number;
  slug: string;
  name: string;
  publisher: string;
  category: string;
  priceFromRm: number;
  imageUrl: string | null;
  featured?: boolean;
  addedAt: string; // ISO date — stands in for games.created_at
  /** Mirrors games.validation_rules.extra_field. */
  extraField: "server_id" | "zone_id" | null;
  /** Mirrors games.player_validator_enabled — only one game demoes the Validate Player ID flow here. */
  playerValidatorEnabled: boolean;
}

export const PLACEHOLDER_GAMES: PlaceholderGame[] = [
  {
    id: 1,
    slug: "mobile-legends",
    name: "Mobile Legends",
    publisher: "Moonton",
    category: "MOBA",
    priceFromRm: 4.5,
    imageUrl: null,
    featured: true,
    addedAt: "2026-06-01",
    extraField: "zone_id",
    playerValidatorEnabled: true,
  },
  {
    id: 2,
    slug: "pubg-mobile",
    name: "PUBG Mobile",
    publisher: "Level Infinite",
    category: "Battle Royale",
    priceFromRm: 4.0,
    imageUrl: null,
    addedAt: "2026-06-02",
    extraField: null,
    playerValidatorEnabled: false,
  },
  {
    id: 3,
    slug: "honor-of-kings",
    name: "Honor of Kings",
    publisher: "TiMi Studio",
    category: "MOBA",
    priceFromRm: 5.0,
    imageUrl: null,
    addedAt: "2026-06-03",
    extraField: "server_id",
    playerValidatorEnabled: false,
  },
  {
    id: 4,
    slug: "free-fire",
    name: "Free Fire",
    publisher: "Garena",
    category: "Battle Royale",
    priceFromRm: 3.0,
    imageUrl: null,
    addedAt: "2026-06-04",
    extraField: null,
    playerValidatorEnabled: false,
  },
  {
    id: 5,
    slug: "valorant",
    name: "Valorant",
    publisher: "Riot Games",
    category: "FPS",
    priceFromRm: 10.0,
    imageUrl: null,
    addedAt: "2026-06-05",
    extraField: "server_id",
    playerValidatorEnabled: false,
  },
  {
    id: 6,
    slug: "roblox",
    name: "Roblox",
    publisher: "Roblox Corp",
    category: "Sandbox",
    priceFromRm: 15.0,
    imageUrl: null,
    addedAt: "2026-06-06",
    extraField: null,
    playerValidatorEnabled: false,
  },
  {
    id: 7,
    slug: "steam-wallet",
    name: "Steam Wallet",
    publisher: "Valve",
    category: "Wallet Code",
    priceFromRm: 5.0,
    imageUrl: null,
    addedAt: "2026-06-07",
    extraField: null,
    playerValidatorEnabled: false,
  },
  {
    id: 8,
    slug: "playstation-store",
    name: "PlayStation Store",
    publisher: "Sony",
    category: "Wallet Code",
    priceFromRm: 50.0,
    imageUrl: null,
    addedAt: "2026-06-08",
    extraField: null,
    playerValidatorEnabled: false,
  },
];

export interface PlaceholderPackage {
  id: number;
  name: string;
  priceRm: number;
}

/** Keyed by game slug — mirrors one game's `packages()` relation. */
export const PLACEHOLDER_PACKAGES: Record<string, PlaceholderPackage[]> = {
  "mobile-legends": [
    { id: 101, name: "86 Diamonds", priceRm: 4.5 },
    { id: 102, name: "172 Diamonds", priceRm: 9.0 },
    { id: 103, name: "257 Diamonds", priceRm: 13.5 },
    { id: 104, name: "706 Diamonds", priceRm: 35.0 },
  ],
  "pubg-mobile": [
    { id: 201, name: "60 UC", priceRm: 4.0 },
    { id: 202, name: "325 UC", priceRm: 20.0 },
    { id: 203, name: "660 UC", priceRm: 40.0 },
  ],
  "honor-of-kings": [
    { id: 301, name: "100 Tokens", priceRm: 5.0 },
    { id: 302, name: "500 Tokens", priceRm: 24.0 },
  ],
  "free-fire": [
    { id: 401, name: "50 Diamonds", priceRm: 3.0 },
    { id: 402, name: "115 Diamonds", priceRm: 6.5 },
    { id: 403, name: "240 Diamonds", priceRm: 13.0 },
  ],
  valorant: [
    { id: 501, name: "475 VP", priceRm: 10.0 },
    { id: 502, name: "1000 VP", priceRm: 20.0 },
  ],
  roblox: [
    { id: 601, name: "400 Robux", priceRm: 15.0 },
    { id: 602, name: "800 Robux", priceRm: 29.0 },
  ],
  "steam-wallet": [
    { id: 701, name: "RM5 Wallet Code", priceRm: 5.0 },
    { id: 702, name: "RM20 Wallet Code", priceRm: 20.0 },
  ],
  "playstation-store": [
    { id: 801, name: "RM50 Wallet Code", priceRm: 50.0 },
    { id: 802, name: "RM100 Wallet Code", priceRm: 100.0 },
  ],
};

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

/** Quick Counter's shortlist — a small, hand-picked subset of PLACEHOLDER_GAMES. */
export const QUICK_COUNTER_SLUGS = ["mobile-legends", "pubg-mobile", "honor-of-kings", "free-fire"];

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
