import { apiFetch } from "@/lib/api-client";

/**
 * ADR-048 addendum: Horizon/Pulse aren't Next.js pages — they're Laravel's
 * own server-rendered dashboards, reached by minting a short-lived (5 min)
 * signed URL that bootstraps the one `web`-guard session this backend ever
 * creates. See backend/app/Http/Controllers/Middleware/OpsAccessController.php's
 * own doc comment for the full story.
 */
export type OpsTarget = "horizon" | "pulse";

export function mintOpsLink(token: string, target: OpsTarget) {
  return apiFetch<{ url: string }>(`/api/middleware/ops/${target}/link`, { method: "POST", token });
}
