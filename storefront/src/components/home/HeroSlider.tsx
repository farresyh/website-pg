"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import Image from "next/image";
import { CaretLeft, CaretRight } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import type { HeroSlide } from "@/lib/hero-slides";

const AUTO_ROTATE_MS = 6000;

/**
 * Real `imageUrl` (docs/prd.md §14/§15 backlog, "Hero Banner / Campaign
 * management") renders as a full-bleed background with a dark gradient
 * scrim for text legibility. No image falls back to the original
 * diagonal-cut CSS-gradient treatment (clip-path) — never a broken
 * <img>. Auto-rotate respects prefers-reduced-motion (ui-ux-pro-max
 * §1/§7). Renders nothing if there are no live slides — no fabricated
 * fallback copy.
 */
export default function HeroSlider({ slides }: { slides: HeroSlide[] }) {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const reducedMotionRef = useRef(false);

  useEffect(() => {
    reducedMotionRef.current = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }, []);

  useEffect(() => {
    if (paused || reducedMotionRef.current || slides.length <= 1) return;
    const timer = setInterval(() => {
      setIndex((prev) => (prev + 1) % slides.length);
    }, AUTO_ROTATE_MS);
    return () => clearInterval(timer);
  }, [paused, slides.length]);

  const next = useCallback(() => setIndex((prev) => (prev + 1) % slides.length), [slides.length]);
  const prev = useCallback(() => setIndex((prev) => (prev - 1 + slides.length) % slides.length), [slides.length]);

  if (slides.length === 0) return null;

  const slide = slides[index % slides.length];

  return (
    <div
      className="relative h-[340px] overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-surface-2 via-surface to-bg-deep lg:h-[400px]"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
      {slide.imageUrl ? (
        <>
          <Image src={slide.imageUrl} alt="" fill className="object-cover" priority />
          <div className="absolute inset-0 bg-gradient-to-r from-bg-deep via-bg-deep/70 to-transparent" aria-hidden="true" />
        </>
      ) : (
        <>
          <div
            className="absolute -top-10 -right-24 h-[160%] w-2/3 -rotate-12 bg-brand/25"
            style={{ clipPath: "polygon(30% 0%, 100% 0%, 70% 100%, 0% 100%)" }}
            aria-hidden="true"
          />
          <div
            className="absolute inset-y-0 right-0 w-1/3 bg-brand-light/10"
            style={{ clipPath: "polygon(60% 0%, 100% 0%, 100% 100%, 20% 100%)" }}
            aria-hidden="true"
          />
        </>
      )}

      <div className="relative z-10 flex h-full max-w-[560px] flex-col justify-end p-6 lg:p-10">
        {slide.eyebrow && (
          <span className="mb-1.5 text-[11px] font-bold tracking-widest text-brand-light uppercase">{slide.eyebrow}</span>
        )}
        <h2 className="font-display mb-2 text-[28px] leading-[1.1] tracking-wide lg:text-[36px]">{slide.title}</h2>
        {slide.description && <p className="mb-3 max-w-[420px] text-sm leading-relaxed text-text-muted">{slide.description}</p>}
        {slide.priceFromRm !== null && (
          <p className="mb-3 text-sm text-text-muted">
            Starting at <strong className="text-base text-text">RM{slide.priceFromRm.toFixed(2)}</strong>
          </p>
        )}
        <div className="flex flex-wrap gap-2.5">
          <Button href={slide.primaryCta.href}>{slide.primaryCta.label} →</Button>
          {slide.secondaryCta && (
            <Button href={slide.secondaryCta.href} variant="outline">
              {slide.secondaryCta.label}
            </Button>
          )}
        </div>
      </div>

      {slides.length > 1 && (
        <>
          <button
            onClick={prev}
            aria-label="Previous slide"
            className="absolute top-1/2 left-2.5 z-10 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-bg/70 text-text hover:bg-bg lg:flex"
          >
            <CaretLeft size={18} />
          </button>
          <button
            onClick={next}
            aria-label="Next slide"
            className="absolute top-1/2 right-2.5 z-10 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-bg/70 text-text hover:bg-bg lg:flex"
          >
            <CaretRight size={18} />
          </button>

          <div className="absolute bottom-3.5 left-1/2 z-10 flex -translate-x-1/2 gap-1.5">
            {slides.map((_, i) => (
              <button
                key={i}
                onClick={() => setIndex(i)}
                aria-label={`Slide ${i + 1}`}
                className={`h-2 rounded-full transition-all ${i === index ? "w-5 bg-brand-light" : "w-2 bg-white/30"}`}
              />
            ))}
          </div>
        </>
      )}
    </div>
  );
}
