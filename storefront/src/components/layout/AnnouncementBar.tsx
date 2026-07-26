import { Lightning } from "@phosphor-icons/react/dist/ssr";

export default function AnnouncementBar() {
  return (
    <div className="bg-brand text-on-brand">
      <div className="mx-auto flex max-w-[1200px] items-center justify-between gap-4 px-4 py-2 text-[13px] font-semibold">
        <div className="flex min-w-0 items-center gap-2">
          <Lightning size={16} weight="fill" className="shrink-0" />
          <span className="truncate">Fast top-ups for your favorite games. Delivered in 3 minutes!</span>
        </div>
        <div className="hidden shrink-0 items-center gap-2 font-mono text-xs lg:flex">
          <span>PROMO CODE: SOLOZRUNCIT</span>
          <a href="#promotions" className="underline underline-offset-2">
            View Promotions ›
          </a>
        </div>
      </div>
    </div>
  );
}
