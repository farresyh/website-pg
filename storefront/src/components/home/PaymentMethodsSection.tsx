import { PAYMENT_METHODS } from "@/lib/placeholder-data";

export default function PaymentMethodsSection() {
  return (
    <section className="mx-auto max-w-[1200px] px-4 py-8">
      <h2 className="mb-3.5 text-base font-bold">Available Payment Methods</h2>
      <div className="flex flex-wrap gap-2.5">
        {PAYMENT_METHODS.map((method) => (
          <span key={method} className="rounded-md border border-border px-3.5 py-2 text-[12.5px] font-semibold text-text-muted">
            {method}
          </span>
        ))}
      </div>
    </section>
  );
}
