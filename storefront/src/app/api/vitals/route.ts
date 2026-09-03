/**
 * ADR-071 PR4 (decision 12) — the sink for `WebVitals.tsx`'s beacons.
 * Deliberately just a structured log line, not a database write: the
 * `@vercel/speed-insights` dashboard is the real analysis surface, and
 * a per-request DB round trip for a fire-and-forget metric isn't worth
 * it. `[web-vitals]` lines are greppable in the platform logs for a
 * spot check or an alert rule.
 */
export const dynamic = "force-dynamic";

interface VitalsPayload {
  name?: string;
  value?: number;
  rating?: string;
  id?: string;
  path?: string;
}

export async function POST(request: Request): Promise<Response> {
  let payload: VitalsPayload = {};
  try {
    payload = (await request.json()) as VitalsPayload;
  } catch {
    return new Response(null, { status: 204 });
  }

  if (typeof payload.name === "string" && typeof payload.value === "number") {
    console.log(
      `[web-vitals] ${payload.name} ${payload.value} ${payload.rating ?? "?"} ${payload.path ?? "?"}`,
    );
  }

  return new Response(null, { status: 204 });
}
