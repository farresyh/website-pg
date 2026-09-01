import { PAYMENT_METHODS } from "@/lib/placeholder-data";

export default function PaymentMethodsSection() {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-10">
      <h2 className="mb-5 text-center font-display text-headline-md tracking-tight">Trusted Payment Methods</h2>
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        {PAYMENT_METHODS.map((method) => (
          <div
            key={method}
            className="flex h-20 items-center justify-center rounded-lg border-2 border-ink bg-surface-container-lowest px-3 text-center font-display text-sm font-bold neo"
          >
            {method}
          </div>
        ))}
      </div>
    </section>
  );
}
