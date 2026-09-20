"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import type { TouchEvent } from "react";
import Image from "next/image";
import { Fire } from "@phosphor-icons/react/dist/ssr";
import Button from "@/components/ui/Button";
import type { HeroSlide } from "@/lib/hero-slides";

const AUTO_ROTATE_MS = 6000;

/**
 * ADR-064 (+ 2026-09-19 addendum): the hero is a bordered content card
 * in the neo-brutalist language, sized to a single `aspect-[2/1]` at
 * every breakpoint (matches the documented 1600x800 upload spec) so a
 * spec-correct image never gets cropped on mobile the way a fixed-height
 * box did. A real `imageUrl` renders framed behind a left-anchored
 * scrim, but only when there's text to protect — a title/CTA-less
 * asset-only slide (all copy baked into the image, same pattern as
 * acidgameshop.com/topagentgames.com) renders the image with no scrim
 * and no text overlay at all. No image falls back to a paper content
 * card with a decorative offset block behind it — never a broken <img>,
 * never fabricated copy. Auto-rotate respects prefers-reduced-motion.
 * Renders nothing with no live slides.
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

  // Touch swipe — arrow buttons were removed (2026-09-19: they collided
  // with the left-anchored title), so this + the dots are the only nav.
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
  const hasTextContent = Boolean(
    slide.eyebrow || slide.title || slide.description || slide.priceFromRm !== null || slide.primaryCta || slide.secondaryCta,
  );

  return (
    <div className="relative">
      {/* decorative offset block */}
      <div
        className="absolute -right-2 -top-2 -z-10 hidden h-full w-full rounded-lg border-2 border-ink bg-secondary-fixed-dim lg:block"
        aria-hidden="true"
      />

      <div
        className="relative flex aspect-[2/1] flex-col justify-end overflow-hidden rounded-lg border-2 border-ink bg-surface-container-low neo"
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
            {/* Scrim behind the headline — only rendered when there is
              * text to protect; a pure asset-only slide shows the image
              * clean, same as acidgameshop.com/topagentgames.com. Mobile:
              * bottom-anchored vertical gradient — the text sits at the
              * bottom and spans nearly the full width, so a left→right
              * scrim leaves the right half of the copy over an undimmed
              * image (ADR-071 PR0). Desktop (lg): left-anchored so the
              * image still reads on the right (ADR-064). */}
            {hasTextContent && (
              <div
                className="absolute inset-0 bg-gradient-to-t from-ink/85 via-ink/50 to-ink/15 lg:bg-gradient-to-r lg:from-ink/80 lg:via-ink/40 lg:to-transparent"
                aria-hidden="true"
              />
            )}
          </>
        )}

        {hasTextContent && (
          <div
            className={`relative z-10 flex max-w-[560px] flex-col items-start gap-2 p-4 lg:gap-3 lg:p-10 ${
              // Reserve room for the dot indicators (bottom-3.5, ~24px
              // tall) so a bottom-anchored CTA row never sits under them.
              slides.length > 1 ? "pb-8 lg:pb-10" : ""
            } ${hasImage ? "text-surface-container-lowest" : "text-on-surface"}`}
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
            {slide.title && (
              <h2 className="font-display text-[22px] font-bold leading-[1.05] tracking-tight sm:text-[32px] lg:text-[56px]">{slide.title}</h2>
            )}
            {slide.description && (
              <p
                className={`hidden max-w-[440px] text-sm leading-relaxed sm:block ${hasImage ? "text-surface-container-lowest/90" : "text-on-surface-variant"}`}
              >
                {slide.description}
              </p>
            )}
            {slide.priceFromRm !== null && (
              <p className={`text-sm ${hasImage ? "text-surface-container-lowest/90" : "text-on-surface-variant"}`}>
                Starting at <strong className="font-mono text-base">RM{slide.priceFromRm.toFixed(2)}</strong>
              </p>
            )}
            {(slide.primaryCta || slide.secondaryCta) && (
              <div className="mt-1 flex flex-wrap gap-3">
                {slide.primaryCta && <Button href={slide.primaryCta.href}>{slide.primaryCta.label}</Button>}
                {slide.secondaryCta && (
                  <Button href={slide.secondaryCta.href} variant="outline">
                    {slide.secondaryCta.label}
                  </Button>
                )}
              </div>
            )}
          </div>
        )}

        {slides.length > 1 && (
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
        )}
      </div>
    </div>
  );
}
