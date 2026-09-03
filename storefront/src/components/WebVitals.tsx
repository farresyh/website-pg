"use client";

import { useReportWebVitals } from "next/web-vitals";

/**
 * ADR-071 PR4 (decision 12) — reports Core Web Vitals to `/api/vitals`.
 * `@vercel/speed-insights` gives the standard CWV dashboard, but its
 * free tier samples; this catches every measurement in the platform
 * logs so a regression against ADR-071's budget (feedback < 100ms,
 * INP < 200ms, LCP < 2.5s) is visible even when a data point was
 * sampled out.
 *
 * `navigator.sendBeacon` so the report survives the page being closed
 * or navigated away from (the moment the final LCP / INP is known).
 */
export default function WebVitals() {
  useReportWebVitals((metric) => {
    const body = JSON.stringify({
      name: metric.name,
      value: Math.round(metric.name === "CLS" ? metric.value * 1000 : metric.value),
      rating: metric.rating,
      id: metric.id,
      path: window.location.pathname,
    });
    if (navigator.sendBeacon) {
      navigator.sendBeacon("/api/vitals", body);
    } else {
      fetch("/api/vitals", { body, method: "POST", keepalive: true }).catch(() => {});
    }
  });

  return null;
}
