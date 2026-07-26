"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { CaretLeft, CaretRight } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import { HERO_SLIDES } from "@/lib/placeholder-data";

const AUTO_ROTATE_MS = 6000;

/**
 * Diagonal-cut treatment (clip-path) echoes the real banner asset's
 * energy instead of the flat rounded-rect card the original draft
 * used. Auto-rotate respects prefers-reduced-motion — the original
 * draft's setInterval had no such check (ui-ux-pro-max §1/§7).
 */
export default function HeroSlider() {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  const reducedMotionRef = useRef(false);

  useEffect(() => {
    reducedMotionRef.current = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  }, []);

  useEffect(() => {
    if (paused || reducedMotionRef.current) return;
    const timer = setInterval(() => {
      setIndex((prev) => (prev + 1) % HERO_SLIDES.length);
    }, AUTO_ROTATE_MS);
    return () => clearInterval(timer);
  }, [paused]);

  const next = useCallback(() => setIndex((prev) => (prev + 1) % HERO_SLIDES.length), []);
  const prev = useCallback(() => setIndex((prev) => (prev - 1 + HERO_SLIDES.length) % HERO_SLIDES.length), []);

  const slide = HERO_SLIDES[index];

  return (
    <div
      className="relative h-[340px] overflow-hidden rounded-2xl border border-border bg-gradient-to-br from-surface-2 via-surface to-bg-deep lg:h-[400px]"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
    >
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

      <div className="relative z-10 flex h-full max-w-[560px] flex-col justify-end p-6 lg:p-10">
        <span className="mb-1.5 text-[11px] font-bold tracking-widest text-brand-light uppercase">{slide.eyebrow}</span>
        <h2 className="font-display mb-2 text-[28px] leading-[1.1] tracking-wide lg:text-[36px]">{slide.title}</h2>
        <p className="mb-3 max-w-[420px] text-sm leading-relaxed text-text-muted">{slide.description}</p>
        {slide.priceFromRm !== undefined && (
          <p className="mb-3 text-sm text-text-muted">
            Starting at <strong className="text-base text-text">RM{slide.priceFromRm.toFixed(2)}</strong>
          </p>
        )}
        <div className="flex flex-wrap gap-2.5">
          <Button href={slide.primaryCta.href}>{slide.primaryCta.label} →</Button>
          <Button href={slide.secondaryCta.href} variant="outline">
            {slide.secondaryCta.label}
          </Button>
        </div>
      </div>

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
        {HERO_SLIDES.map((_, i) => (
          <button
            key={i}
            onClick={() => setIndex(i)}
            aria-label={`Slide ${i + 1}`}
            className={`h-2 rounded-full transition-all ${i === index ? "w-5 bg-brand-light" : "w-2 bg-white/30"}`}
          />
        ))}
      </div>
    </div>
  );
}
