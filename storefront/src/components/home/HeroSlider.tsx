"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { TouchEvent } from "react";
import Image from "next/image";
import { CaretLeft, CaretRight, Fire } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import type { HeroSlide } from "@/lib/hero-slides";

const AUTO_ROTATE_MS = 6000;

/**
 * ADR-064: the hero is a bordered content card in the neo-brutalist
 * language. A real `imageUrl` (admin-managed, "Hero Banner / Campaign
 * management") renders framed behind a left-anchored scrim; no image
 * falls back to a paper content card with a decorative offset block
 * behind it — never a broken <img>, never fabricated copy. Auto-rotate
 * respects prefers-reduced-motion. Renders nothing with no live slides.
 */
export default function HeroSlider({ slides }: { slides: HeroSlide[] }) {
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  // A slide whose image URL 404s (deleted asset, missing storage link)
  // falls back to the no-image paper layout rather than showing a dark
  // scrim over nothing.
  const [brokenImages, setBrokenImages] = useState<Set<number>>(new Set());
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

  // Touch swipe — the prev/next arrows are pointer-only (lg:flex), so
  // without this a phone can only page via the dot indicators.
  const touchStartX = useRef<number | null>(null);
  const onTouchStart = (e: TouchEvent) => {
    touchStartX.current = e.touches[0]?.clientX ?? null;
  };
  const onTouchEnd = (e: TouchEvent) => {
    if (touchStartX.current === null || slides.length <= 1) return;
    const dx = (e.changedTouches[0]?.clientX ?? touchStartX.current) - touchStartX.current;
    if (dx <= -40) next();
    else if (dx >= 40) prev();
    touchStartX.current = null;
  };

  if (slides.length === 0) return null;

  const slide = slides[index % slides.length];
  const hasImage = Boolean(slide.imageUrl) && !brokenImages.has(slide.id);

  return (
    <div className="relative">
      {/* decorative offset block */}
      <div
        className="absolute -right-2 -top-2 -z-10 hidden h-full w-full rounded-lg border-2 border-ink bg-secondary-fixed-dim lg:block"
        aria-hidden="true"
      />

      <div
        className="relative flex min-h-[360px] flex-col justify-end overflow-hidden rounded-lg border-2 border-ink bg-surface-container-low neo lg:h-[420px] lg:min-h-0"
        onMouseEnter={() => setPaused(true)}
        onMouseLeave={() => setPaused(false)}
        onTouchStart={onTouchStart}
        onTouchEnd={onTouchEnd}
      >
        {hasImage && (
          <>
            <Image
              src={slide.imageUrl as string}
              alt=""
              fill
              sizes="(min-width: 1024px) 66vw, 100vw"
              className="object-cover"
              priority
              onError={() => setBrokenImages((prev) => new Set(prev).add(slide.id))}
            />
            {/* Scrim behind the headline. Mobile: bottom-anchored
              * vertical gradient — the text sits at the bottom and spans
              * nearly the full width, so a left→right scrim leaves the
              * right half of the copy over an undimmed image (ADR-071
              * PR0). Desktop (lg): left-anchored so the image still reads
              * on the right (ADR-064). */}
            <div
              className="absolute inset-0 bg-gradient-to-t from-ink/85 via-ink/50 to-ink/15 lg:bg-gradient-to-r lg:from-ink/80 lg:via-ink/40 lg:to-transparent"
              aria-hidden="true"
            />
          </>
        )}

        <div
          className={`relative z-10 flex max-w-[560px] flex-col items-start gap-3 p-5 lg:p-10 ${
            hasImage ? "text-surface-container-lowest" : "text-on-surface"
          }`}
        >
          {slide.eyebrow && (
            <span
              className={`inline-flex items-center gap-1.5 rounded-full border-2 border-ink px-3 py-1 font-display text-[11px] font-bold uppercase tracking-wide ${
                hasImage ? "bg-surface-container-lowest text-on-surface" : "bg-surface-container"
              }`}
            >
              <Fire size={13} weight="fill" className="text-tertiary" />
              {slide.eyebrow}
            </span>
          )}
          <h2 className="font-display text-[32px] font-bold leading-[1.05] tracking-tight sm:text-[38px] lg:text-[56px]">{slide.title}</h2>
          {slide.description && (
            <p className={`max-w-[440px] text-sm leading-relaxed ${hasImage ? "text-surface-container-lowest/90" : "text-on-surface-variant"}`}>
              {slide.description}
            </p>
          )}
          {slide.priceFromRm !== null && (
            <p className={`text-sm ${hasImage ? "text-surface-container-lowest/90" : "text-on-surface-variant"}`}>
              Starting at <strong className="font-mono text-base">RM{slide.priceFromRm.toFixed(2)}</strong>
            </p>
          )}
          <div className="mt-1 flex flex-wrap gap-3">
            <Button href={slide.primaryCta.href}>{slide.primaryCta.label}</Button>
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
              className="absolute left-3 top-1/2 z-10 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-md border-2 border-ink bg-surface-container-lowest text-ink neo-sm hover:bg-surface-container lg:flex"
            >
              <CaretLeft size={18} weight="bold" />
            </button>
            <button
              onClick={next}
              aria-label="Next slide"
              className="absolute right-3 top-1/2 z-10 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-md border-2 border-ink bg-surface-container-lowest text-ink neo-sm hover:bg-surface-container lg:flex"
            >
              <CaretRight size={18} weight="bold" />
            </button>

            <div className="absolute bottom-3.5 left-1/2 z-10 flex -translate-x-1/2 gap-1.5">
              {slides.map((_, i) => (
                <button
                  key={i}
                  onClick={() => setIndex(i)}
                  aria-label={`Slide ${i + 1}`}
                  className={`h-2.5 rounded-full border border-ink transition-all ${i === index ? "w-6 bg-primary" : "w-2.5 bg-surface-container-lowest"}`}
                />
              ))}
            </div>
          </>
        )}
      </div>
    </div>
  );
}
