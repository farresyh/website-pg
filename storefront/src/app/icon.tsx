import { ImageResponse } from "next/og";
import { getBranding } from "@/lib/branding";

/**
 * ADR-089: per-brand favicon (host-resolved, same `getBranding()` seam
 * every other branding surface uses — ADR-060). `getBranding()` reads
 * `next/headers()` internally, a Request-time API, so this route is
 * dynamic per request rather than statically optimized at build time —
 * required, since the resolved brand (and therefore the favicon) varies
 * by `Host`.
 *
 * A real uploaded favicon (`affiliate_branding.favicon_path`, always a
 * square WebP per `UploadFaviconRequest`) is proxied through as-is. No
 * upload yet falls back to the same bolt-in-square mark as `Logo.tsx`'s
 * placeholder, rendered via `ImageResponse` instead of duplicating the
 * SVG markup a third time.
 */
export const size = { width: 512, height: 512 };

export default async function Icon() {
  const branding = await getBranding();

  if (branding.faviconUrl) {
    const upstream = await fetch(branding.faviconUrl);
    if (upstream.ok) {
      return new Response(upstream.body, {
        headers: { "Content-Type": upstream.headers.get("content-type") ?? "image/webp" },
      });
    }
  }

  return new ImageResponse(
    (
      <div
        style={{
          width: "100%",
          height: "100%",
          display: "flex",
          alignItems: "center",
          justifyContent: "center",
          background: "#6b38d4",
        }}
      >
        <svg width="70%" height="70%" viewBox="0 0 32 32" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path
            d="M17.6 5 L9 18.2 H15 L13.2 27 L23 13.4 H16.6 Z"
            fill="#ffffff"
            stroke="#19192f"
            strokeWidth="1.5"
            strokeLinejoin="round"
          />
        </svg>
      </div>
    ),
    { ...size },
  );
}
