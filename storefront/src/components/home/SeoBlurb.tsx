import Link from "next/link";

export default function SeoBlurb() {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-9 lg:py-12">
      <div className="rounded-lg border-2 border-ink bg-surface-container-low p-6 neo">
        <h2 className="mb-3 font-display text-headline-md tracking-tight">Top Up Games in Malaysia at PekanGame</h2>
        <p className="max-w-[900px] text-[13px] leading-relaxed text-on-surface-variant">
          PekanGame offers the fastest top-up platform for gaming fans across Malaysia. Get the best prices for all your
          favorite games.{" "}
          <Link href="/about-us" className="font-bold text-primary underline underline-offset-2">
            Read More ›
          </Link>
        </p>
      </div>
    </section>
  );
}
