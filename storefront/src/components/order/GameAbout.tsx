import type { GameContent } from "@/lib/catalog";

/**
 * ADR-140 decision 9: a game's own "About" and FAQ, below the reviews and above
 * the footer. Server-rendered, visible (no collapse, no popup), and marked
 * `lang="ms"` because the content is written in Malay on an English page. The
 * text is plain: React escapes it, and a blank line starts a new paragraph.
 * The same FAQ items feed the page's `FAQPage` JSON-LD, so the markup always
 * matches what a visitor reads. Absent when there is nothing to show (an empty
 * or draft game, or any brand but the primary: the backend sends no `content`).
 */
export default function GameAbout({ gameName, content }: { gameName: string; content: GameContent }) {
  const paragraphs = (content.about ?? "")
    .split(/\n\s*\n/)
    .map((paragraph) => paragraph.trim())
    .filter(Boolean);
  const summary = content.summary?.trim();

  if (!summary && paragraphs.length === 0 && content.faq.length === 0) return null;

  return (
    <section lang="ms" aria-labelledby="game-about-heading" className="py-9 lg:py-12">
      <div className="mx-auto max-w-[1200px] px-4">
        <div className="max-w-[760px]">
          <h2 id="game-about-heading" className="font-display text-lg font-bold uppercase tracking-tight text-on-surface sm:text-xl">
            Tentang {gameName}
          </h2>
          {summary && <p className="mt-3 text-base font-medium leading-relaxed text-on-surface">{summary}</p>}
          {paragraphs.map((paragraph, index) => (
            <p key={index} className="mt-3 text-sm leading-relaxed text-on-surface-variant">
              {paragraph}
            </p>
          ))}

          {content.faq.length > 0 && (
            <div className="mt-8">
              <h3 className="font-display text-base font-bold uppercase tracking-tight text-on-surface">Soalan Lazim</h3>
              <dl className="mt-3 divide-y-2 divide-ink/15 border-y-2 border-ink/15">
                {content.faq.map((item, index) => (
                  <div key={index} className="py-4">
                    <dt className="text-sm font-bold text-on-surface">{item.question}</dt>
                    <dd className="mt-1.5 text-sm leading-relaxed text-on-surface-variant">{item.answer}</dd>
                  </div>
                ))}
              </dl>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
