import { PaymentChannelIcon } from "@/components/icons/PaymentIcons";
import type { PaymentChannel } from "@/lib/payment-methods";

export default function PaymentMethodsSection({ channels = [] }: { channels?: PaymentChannel[] }) {
  if (!channels || channels.length === 0) return null;

  return (
    <section className="mx-auto max-w-[1200px] px-4 py-9 lg:py-10">
      <h2 className="mb-6 text-center font-display text-headline-md tracking-tight">
        Trusted Payment Methods
      </h2>
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4">
        {channels.map((channel) => (
          <div
            key={channel.channelCode}
            className="flex min-h-[72px] items-center justify-center gap-3 rounded-lg border-2 border-ink bg-surface-container-lowest p-4 text-center font-display text-[13px] font-bold neo transition-all hover:bg-surface-container-low"
          >
            <PaymentChannelIcon
              channelCode={channel.channelCode}
              category={channel.category}
              className="h-6 w-auto shrink-0"
            />
            <span className="leading-tight">{channel.label}</span>
          </div>
        ))}
      </div>
    </section>
  );
}

