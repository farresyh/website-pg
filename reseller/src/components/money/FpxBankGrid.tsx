import Image from "next/image";
import banks from "@/lib/fpx-banks.json";

/** False when the bank list is empty: the wallet then shows the plain FPX radio again. */
export const HAS_FPX_BANKS = banks.length > 0;

/**
 * ADR-130: the FPX banks as logo tiles, so the reseller goes straight to their
 * bank instead of choosing on CHIP's page. "Other bank" sends the same request
 * with no bank. The list and logos are generated copies of the storefront's
 * (scripts/sync-shared-files.mjs); only the styling is the portal's own.
 */
export default function FpxBankGrid({
  selected,
  bankCode,
  onSelect,
}: {
  selected: boolean;
  bankCode: string | null;
  onSelect: (bankCode: string | null) => void;
}) {
  return (
    <>
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
        {banks.map((bank) => {
          const on = selected && bankCode === bank.code;
          return (
            <button
              key={bank.code}
              type="button"
              aria-label={bank.name}
              aria-pressed={on}
              title={bank.name}
              onClick={() => onSelect(bank.code)}
              className={`flex h-12 items-center justify-center rounded-md border bg-white px-2 ${
                on ? "border-cyan-600 shadow-[inset_0_0_0_1px_var(--color-cyan-600)]" : "border-border-control hover:border-cyan-600"
              }`}
            >
              <Image
                src={`/images/banks/${bank.code.toLowerCase()}.png`}
                alt=""
                width={240}
                height={96}
                className="h-auto max-h-7 w-auto max-w-full object-contain"
              />
            </button>
          );
        })}
      </div>
      <button
        type="button"
        aria-pressed={selected && bankCode === null}
        onClick={() => onSelect(null)}
        className={`mt-2 text-xs underline underline-offset-2 ${selected && bankCode === null ? "font-semibold text-ink" : "text-ink-subtle"}`}
      >
        Other bank? Choose it on the next page
      </button>
    </>
  );
}
