/**
 * Static placeholder content for the homepage. Games/packages moved to
 * lib/catalog.ts, hero slides to lib/hero-slides.ts, and payment
 * channels to lib/payment-methods.ts (all real backend data) —
 * everything remaining here (testimonials, FAQ) still has no backing
 * model, kept as honest static content rather than a fake dynamic
 * system.
 *
 * The "This Week's Promotions" section and its PROMOTIONS array were
 * removed in ADR-071 PR0: hardcoded promos with past "Ends:" dates were
 * live in production. Re-introducing promotions requires a real
 * admin-editable model (its own future ADR), not placeholder data.
 */

export interface Testimonial {
  name: string;
  rating: number;
  quote: string;
}

export const TESTIMONIALS: Testimonial[] = [
  { name: "Ahmad R.", rating: 5, quote: "Fast process — diamonds arrived within 2 minutes of payment." },
  { name: "Nurul S.", rating: 5, quote: "Very easy and safe. Only the first-time setup takes a bit of time." },
  { name: "Hafiz M.", rating: 5, quote: "Cheapest UC prices in Malaysia with instant delivery. Best, PekanGame!" },
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
