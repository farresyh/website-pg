import { jsonLdHtml } from "@/lib/seo";
import type { FaqItem } from "@/components/player-check/guides";

/** The checker's FAQ, rendered and as FAQPage JSON-LD (ADR-123 decision 10). */
export default function CheckIdFaq({ items }: { items: FaqItem[] }) {
  const jsonLd = {
    "@context": "https://schema.org",
    "@type": "FAQPage",
    mainEntity: items.map((item) => ({
      "@type": "Question",
      name: item.question,
      acceptedAnswer: { "@type": "Answer", text: item.answer },
    })),
  };

  return (
    <section className="mt-12">
      <script type="application/ld+json" dangerouslySetInnerHTML={{ __html: jsonLdHtml(jsonLd) }} />
      <h2 className="font-display text-xl font-bold uppercase tracking-tight text-on-surface">Questions</h2>
      <div className="mt-4 space-y-2">
        {items.map((item) => (
          <details key={item.question} className="group rounded-md border-2 border-ink bg-surface-container-lowest p-4">
            <summary className="cursor-pointer list-none font-display text-sm font-bold text-on-surface">{item.question}</summary>
            <p className="mt-2 text-sm leading-relaxed text-on-surface-variant">{item.answer}</p>
          </details>
        ))}
      </div>
    </section>
  );
}
