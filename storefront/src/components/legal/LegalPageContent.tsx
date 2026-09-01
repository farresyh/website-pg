/**
 * ADR-028 addendum decision 11/12 — shared by /terms, /privacy,
 * /about-us. `content` is already sanitized (the `rich_text` Purifier
 * profile) and `{store_name}`-substituted server-side
 * (BrandingController::legal()) before this renders it — safe here
 * because the content passed the allowlist sanitizer twice (on save
 * and again at this render point), not merely because it's admin-authored.
 */
export default function LegalPageContent({ title, content }: { title: string; content: string | null }) {
  return (
    <div className="mx-auto max-w-[760px] px-4 py-12 lg:py-16">
      <h1 className="font-display text-headline-lg font-bold uppercase tracking-tight text-on-surface">{title}</h1>
      <div className="mt-6 rounded-lg border-2 border-ink bg-surface-container-lowest p-6 neo lg:p-8">
        {content ? (
          <div
            className="text-sm leading-relaxed text-on-surface-variant [&_a]:text-primary [&_a]:underline [&_h2]:mt-6 [&_h2]:mb-2 [&_h2]:font-display [&_h2]:text-headline-sm [&_h2]:text-on-surface [&_h3]:mt-4 [&_h3]:mb-1.5 [&_h3]:font-display [&_h3]:font-bold [&_h3]:text-on-surface [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-3 [&_ul]:list-disc [&_ul]:pl-5"
            dangerouslySetInnerHTML={{ __html: content }}
          />
        ) : (
          <p className="text-sm text-on-surface-variant">Content coming soon.</p>
        )}
      </div>
    </div>
  );
}
