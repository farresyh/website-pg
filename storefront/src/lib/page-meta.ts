/**
 * ADR-140 decisions 1–2: the one place a static page's title and description
 * live. Dependency-free on purpose (pure strings, no fetch), like seo-meta.ts.
 *
 * Why it exists: these pages used to hardcode a title each and had no
 * description, so four of them (home, about-us, refund-policy, track-order)
 * all fell back to one sentence in Search Console. Now every page has its own.
 *
 * The words follow how Malaysians search (Google Trends, ADR-140 Context):
 * gaming terms stay English ("top up", "diamond"), the rest is plain Malay.
 * A legal page's body is admin-edited, so its meta says what the page is, never
 * what its policy says. No page states a delivery time or a payment method:
 * the data decides those, and they change.
 *
 * `{store_name}` is the brand's own name, so an affiliate's copy of a page
 * carries its name. `price-list` and `check-id` keep their English wording:
 * the first is for resellers, and the second already earns impressions for
 * English queries ("genshin id checker").
 */

export type PageMetaKey =
  | "home"
  | "about-us"
  | "terms"
  | "privacy"
  | "refund-policy"
  | "track-order"
  | "price-list"
  | "check-id";

interface PageMeta {
  title: string;
  description: string;
}

const PAGE_META: Record<PageMetaKey, PageMeta> = {
  home: {
    title: "{store_name} - Top Up Game Murah di Malaysia",
    description:
      "Top up diamond, UC dan kredit game di {store_name}. Pilih game, masukkan ID dan bayar terus. Tanpa daftar akaun.",
  },
  "about-us": {
    title: "Tentang {store_name} - Kedai Top Up Game",
    description:
      "Kenali {store_name}, kedai top up game dalam talian untuk gamer Malaysia: siapa kami dan apa yang kami tawarkan.",
  },
  terms: {
    title: "Terma & Syarat - {store_name}",
    description:
      "Baca terma dan syarat menggunakan {store_name} sebelum anda top up: pembelian, pembayaran dan tanggungjawab pelanggan.",
  },
  privacy: {
    title: "Dasar Privasi - {store_name}",
    description:
      "Bagaimana {store_name} mengumpul, menggunakan dan melindungi maklumat anda apabila anda top up game di sini.",
  },
  "refund-policy": {
    title: "Dasar Bayaran Balik - {store_name}",
    description:
      "Top up anda gagal atau tidak masuk? Baca dasar bayaran balik {store_name} untuk tahu apa yang berlaku seterusnya.",
  },
  "track-order": {
    title: "Semak Status Order Top Up - {store_name}",
    description:
      "Semak status order top up anda di {store_name} dengan nombor order anda, tanpa perlu log masuk atau daftar akaun.",
  },
  "price-list": {
    title: "Reseller Price List - {store_name}",
    description:
      "Real-time wholesale top-up pricing for {store_name} reseller tiers. See the rates before you sign up.",
  },
  "check-id": {
    title: "Game ID Checker: Find Your Nickname & Region - {store_name}",
    description:
      "Check a game ID for free on {store_name}. See the account's nickname and the right store to top up before you pay.",
  },
};

export const PAGE_META_KEYS = Object.keys(PAGE_META) as PageMetaKey[];

/** One pass over the exact `{store_name}` token, so a name that contains braces is left as typed. */
function withStoreName(text: string, storeName: string): string {
  return text.split("{store_name}").join(storeName);
}

export function pageMeta(key: PageMetaKey, storeName: string): PageMeta {
  const { title, description } = PAGE_META[key];
  return { title: withStoreName(title, storeName), description: withStoreName(description, storeName) };
}
