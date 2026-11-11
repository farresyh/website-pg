/**
 * ADR-120 decision 13 + ADR-140 decision 9: the one FAQPage builder. The home
 * page and a game's page both render their FAQ from the same items they pass
 * here, so the structured data always matches the visible text (a Google
 * requirement). Dependency-free.
 */
export interface FaqEntry {
  question: string;
  answer: string;
}

export function faqPageJsonLd(items: FaqEntry[]) {
  if (items.length === 0) return null;

  return {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: items.map((item) => ({
      "@type": "Question",
      name: item.question,
      acceptedAnswer: { "@type": "Answer", text: item.answer },
    })),
  };
}
