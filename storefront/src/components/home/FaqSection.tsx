import { CaretDown } from "@phosphor-icons/react/dist/ssr";
import SectionHeading from "@/components/home/SectionHeading";
import { FAQ_ITEMS } from "@/lib/placeholder-data";

/**
 * Native <details>/<summary> — fully keyboard accessible and
 * screen-reader friendly without any custom JS accordion logic.
 */
export default function FaqSection() {
  return (
    <section className="mx-auto max-w-[840px] px-4 py-9 lg:py-12">
      <SectionHeading title="Frequently Asked Questions" />
      <div className="flex flex-col gap-3">
        {FAQ_ITEMS.map((item) => (
          <details
            key={item.question}
            className="group rounded-lg border-2 border-ink bg-surface-container-lowest neo open:bg-surface-container-low"
          >
            <summary className="flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 p-5 font-display text-headline-sm">
              {item.question}
              <CaretDown
                size={18}
                weight="bold"
                className="shrink-0 text-primary transition-transform group-open:rotate-180"
              />
            </summary>
            <p className="px-5 pb-5 text-[13px] leading-relaxed text-on-surface-variant">{item.answer}</p>
          </details>
        ))}
      </div>
    </section>
  );
}
