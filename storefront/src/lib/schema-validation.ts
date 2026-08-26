import { z } from "zod";

/**
 * ADR-044 decision 8 — fire-and-forget report of a response-schema
 * mismatch to the backend's `/api/client-errors` log sink. Best-effort
 * only: a failed drift report must never itself surface to the user,
 * so its own network failure is swallowed. `console.error` first so
 * the drift is visible immediately even if the report never lands.
 */
function reportSchemaDrift(schemaName: string, path: string, error: string): void {
  console.error(`[schema-drift] ${schemaName} at ${path}: ${error}`);

  const baseUrl = process.env.NEXT_PUBLIC_API_URL ?? "http://backend.test";
  fetch(`${baseUrl}/api/client-errors`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ schema: schemaName, path, error }),
  }).catch(() => {
    // Best-effort — see doc comment above.
  });
}

/**
 * Parses `raw` against `schema`; on a genuine mismatch (missing or
 * wrong-typed field) reports the drift and falls back to returning the
 * raw payload as-is rather than throwing (ADR-044 decision 5) —
 * response validation exists to detect backend contract drift, not to
 * add a new failure mode to the money path. An extra/unrecognized
 * field never triggers this: a plain `z.object()` already strips
 * unknown keys instead of failing, so passthrough is the default, not
 * something opted into here.
 */
export function parseResponse<T>(schema: z.ZodType<T>, raw: unknown, schemaName: string, path: string): T {
  const result = schema.safeParse(raw);
  if (result.success) return result.data;
  reportSchemaDrift(schemaName, path, result.error.message);
  return raw as T;
}
