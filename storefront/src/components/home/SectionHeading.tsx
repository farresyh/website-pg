import Link from "next/link";

/**
 * ADR-064 — the Stitch section-header rhythm: a heavy display heading
 * with an optional "View All" link baselined to its right.
 */
export default function SectionHeading({
  title,
  link,
  className = "",
}: {
  title: string;
  link?: { label: string; href: string };
  className?: string;
}) {
  return (
    <div className={`mb-6 flex flex-wrap items-end justify-between gap-3 ${className}`}>
      <h2 className="font-display text-headline-md tracking-tight lg:text-headline-lg">{title}</h2>
      {link &&
        (link.href.startsWith("#") ? (
          <a href={link.href} className="font-display text-[13px] font-bold uppercase tracking-wide text-primary hover:underline">
            {link.label} ›
          </a>
        ) : (
          <Link href={link.href} className="font-display text-[13px] font-bold uppercase tracking-wide text-primary hover:underline">
            {link.label} ›
          </Link>
        ))}
    </div>
  );
}
