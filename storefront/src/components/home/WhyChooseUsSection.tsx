import { Lightning, ShieldCheck, Stack, ChatCircleDots } from "@phosphor-icons/react/dist/ssr";

const REASONS = [
  { icon: Lightning, title: "Fast Process", description: "Automatic top-up within 3 minutes" },
  { icon: ShieldCheck, title: "Secure Payment", description: "Transactions protected with SSL encryption" },
  { icon: Stack, title: "Wide Product Range", description: "Over 200 games and digital products" },
  { icon: ChatCircleDots, title: "Customer Support", description: "Friendly support via WhatsApp & email, 24/7" },
];

export default function WhyChooseUsSection() {
  return (
    <section className="border-y border-border bg-surface py-10">
      <div className="mx-auto max-w-[1200px] px-4">
        <h2 className="font-display mb-7 text-center text-[22px] tracking-wide">Why Choose Kedai Runcit Soloz?</h2>
        <div className="grid grid-cols-2 gap-6 lg:grid-cols-4">
          {REASONS.map(({ icon: Icon, title, description }) => (
            <div key={title} className="flex flex-col items-center gap-2.5 text-center">
              <div className="flex h-12 w-12 items-center justify-center rounded-full border border-border bg-bg text-brand-light">
                <Icon size={22} />
              </div>
              <h4 className="text-sm font-bold">{title}</h4>
              <p className="text-[12.5px] leading-relaxed text-text-muted">{description}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}
