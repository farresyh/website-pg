import { Lightning, ShieldCheck, Stack, ChatCircleDots } from "@phosphor-icons/react/dist/ssr";

const REASONS = [
  { icon: Lightning, title: "Fast Process", description: "Automatic top-up within 3 minutes" },
  { icon: ShieldCheck, title: "Secure Payment", description: "Transactions protected with SSL encryption" },
  { icon: Stack, title: "Wide Product Range", description: "Over 200 games and digital products" },
  { icon: ChatCircleDots, title: "Customer Support", description: "Friendly support via WhatsApp & email, 24/7" },
];

export default function WhyChooseUsSection() {
  return (
    <section className="border-y-2 border-ink bg-surface-container py-12">
      <div className="mx-auto max-w-[1200px] px-4">
        <h2 className="font-display mb-9 text-center text-headline-md tracking-tight">Why Choose PekanGame?</h2>
        <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
          {REASONS.map(({ icon: Icon, title, description }) => (
            <div key={title} className="flex flex-col items-center gap-3 text-center">
              <div className="flex h-14 w-14 items-center justify-center rounded-md border-2 border-ink bg-secondary-fixed-dim text-ink neo-sm">
                <Icon size={24} weight="fill" />
              </div>
              <h3 className="font-display text-base font-bold">{title}</h3>
              <p className="text-[12.5px] leading-relaxed text-on-surface-variant">{description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
