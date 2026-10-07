import { z } from "zod";
import { apiFetch } from "@/lib/api-client";
import { parseResponse } from "@/lib/schema-validation";
import { catalogCache, safeRead } from "@/lib/cache";

/**
 * ADR-120 decision 13: the platform FAQ, admin-authored, with
 * `{store_name}` already resolved server-side for the serving brand.
 * Plain text — rendered escaped. Empty on a backend blip, which hides
 * the section rather than showing stale copy.
 */
const FaqItemSchema = z.object({ question: z.string(), answer: z.string() });

export type FaqItem = z.infer<typeof FaqItemSchema>;

export async function listFaqs(): Promise<FaqItem[]> {
  const path = "/api/catalog/seo/faqs";
  return safeRead(
    "listFaqs",
    async () => {
      const raw = await apiFetch<unknown>(path, { next: catalogCache });
      return parseResponse(z.array(FaqItemSchema), raw, "FaqItem[]", path);
    },
    [],
  );
}
