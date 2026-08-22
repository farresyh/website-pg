/**
 * ADR-028 addendum decision 11/12 — shared by /terms, /privacy,
 * /about-us. `content` is already sanitized (the `rich_text` Purifier
 * profile, decision 12) and `{store_name}`-substituted server-side
 * (BrandingController::legal()) before this ever renders it — first
 * use of `dangerouslySetInnerHTML` in this codebase, safe here
 * because the content passed through the allowlist sanitizer twice
 * (once on save, once again at this render point), not because it's
 * merely admin-authored.
 */
export default function LegalPageContent({ title, content }: { title: string; content: string | null }) {
  return (
    <div className="mx-auto max-w-[720px] px-4 py-10 lg:py-16">
      <h1 className="font-display text-2xl text-text">{title}</h1>
      {content ? (
        <div
          className="mt-6 text-sm leading-relaxed text-text-muted [&_a]:text-brand-light [&_a]:underline [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-3 [&_ul]:list-disc [&_ul]:pl-5"
          dangerouslySetInnerHTML={{ __html: content }}
        />
      ) : (
        <p className="mt-6 text-sm text-text-muted">Content coming soon.</p>
      )}
    </div>
  );
}
