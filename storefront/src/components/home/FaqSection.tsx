import { CaretDown } from "@phosphor-icons/react/dist/ssr";
import { FAQ_ITEMS } from "@/lib/placeholder-data";

/**
 * Native <details>/<summary> — fully keyboard accessible and
 * screen-reader friendly without any custom JS accordion logic.
 */
export default function FaqSection() {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-10">
      <div className="mb-5 flex flex-wrap items-baseline justify-between gap-3">
        <h2 className="font-display text-[22px] tracking-wide">Frequently Asked Questions</h2>
      </div>
      <div className="border-t border-border">
        {FAQ_ITEMS.map((item) => (
          <details key={item.question} className="group border-b border-border">
            <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 py-4 text-sm font-semibold">
              {item.question}
              <CaretDown size={16} className="shrink-0 text-text-muted transition-transform group-open:rotate-180 group-open:text-brand-light" />
            </summary>
            <p className="pb-4 text-[13px] leading-relaxed text-text-muted">{item.answer}</p>
          </details>
        ))}
      </div>
    </section>
  );
}
