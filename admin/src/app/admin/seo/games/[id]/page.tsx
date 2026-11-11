"use client";

/**
 * ADR-140 decisions 6, 8 and 12: a game's own page content (summary, about, FAQ,
 * published or not) above the per-game SEO exceptions, on the PrimeReact kit.
 * The content shows on the primary brand's storefront only, and only once
 * published; the backend decides that, this screen just writes it.
 */

import { useEffect, useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import { Label } from "@/components/ui/label";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { Button } from "@/components/ui/button";
import { Tag } from "@/components/ui/tag";
import { Switch } from "@/components/ui/switch";
import { ImageUrlInput } from "@/components/gallery/ImageUrlInput";
import { getClientSession } from "@/lib/session";
import { useClientSession } from "@/hooks/useClientSession";
import { ApiError } from "@/lib/api-client";
import { notify } from "@/components/ui/toast";
import {
  getGameSeo,
  updateGameContent,
  updateGameSeo,
  type GameContentStatus,
  type GameFaqItem,
  type GameSeoDetail,
} from "@/lib/seo";

const SUMMARY_MAX = 300;
const ABOUT_MAX = 2000;
const FAQ_MAX_ITEMS = 8;
const QUESTION_MAX = 150;
const ANSWER_MAX = 500;

const STATUS_TAG: Record<GameContentStatus, { severity: "success" | "warn" | "danger"; label: string }> = {
  published: { severity: "success", label: "Published" },
  draft: { severity: "warn", label: "Draft" },
  empty: { severity: "danger", label: "Empty" },
};

const CARD = "rounded-2xl border border-border bg-surface p-5";

interface ContentForm {
  summary: string;
  about: string;
  faq: GameFaqItem[];
  published: boolean;
}

function contentFormOf(game: GameSeoDetail): ContentForm {
  return {
    summary: game.content_summary ?? "",
    about: game.content_about ?? "",
    faq: game.content_faq ?? [],
    published: game.content_status === "published",
  };
}

export default function GameSeoEditPage() {
  const router = useRouter();
  const params = useParams<{ id: string }>();
  const gameId = Number(params.id);

  const session = useClientSession();
  const [game, setGame] = useState<GameSeoDetail | null>(null);
  const [content, setContent] = useState<ContentForm | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [savingSeo, setSavingSeo] = useState(false);
  const [savingContent, setSavingContent] = useState(false);

  useEffect(() => {
    const s = getClientSession();
    if (!s) {
      router.replace("/login");
      return;
    }
    getGameSeo(gameId)
      .then((loaded) => {
        setGame(loaded);
        setContent(contentFormOf(loaded));
      })
      .catch((err: unknown) => setError(err instanceof ApiError ? err.message : "Could not load game."));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [gameId]);

  async function handleSaveContent() {
    if (!game || !content) return;
    setSavingContent(true);
    try {
      const updated = await updateGameContent(gameId, {
        summary: content.summary.trim() || null,
        about: content.about.trim() || null,
        // A half-filled row is a mistake the server would refuse; a blank one is just an unused row.
        faq: content.faq.filter((item) => item.question.trim() !== "" || item.answer.trim() !== ""),
        published: content.published,
      });
      setGame(updated);
      setContent(contentFormOf(updated));
      notify.success(updated.content_status === "published" ? "Content saved and published" : "Content saved as a draft");
    } catch (err) {
      notify.error("Could not save content", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setSavingContent(false);
    }
  }

  async function handleSaveSeo() {
    if (!session || !game) return;
    setSavingSeo(true);
    try {
      const updated = await updateGameSeo(gameId, {
        seo_title: game.seo_title || null,
        seo_description: game.seo_description || null,
        seo_og_image: game.seo_og_image || null,
        schema_brand: game.schema_brand || null,
        schema_category: game.schema_category || null,
        no_index: game.no_index,
      });
      setGame(updated);
      notify.success("Game SEO saved");
    } catch (err) {
      notify.error("Could not save game SEO", err instanceof ApiError ? err.message : "Action failed.");
    } finally {
      setSavingSeo(false);
    }
  }

  function setFaqItem(index: number, patch: Partial<GameFaqItem>) {
    if (!content) return;
    setContent({ ...content, faq: content.faq.map((item, i) => (i === index ? { ...item, ...patch } : item)) });
  }

  if (!session || !game || !content) {
    return <p className="text-sm text-ink-muted">{error ?? "Loading…"}</p>;
  }

  const status = STATUS_TAG[game.content_status];

  return (
    <div>
      <div className="mb-6">
        <Link href="/admin/seo/games" className="text-sm text-brand-500 hover:underline">← Back to Game SEO</Link>
        <div className="mt-2 flex flex-wrap items-center gap-3">
          <h1 className="text-xl font-semibold text-ink">{game.name}</h1>
          <Tag severity={status.severity}>{status.label}</Tag>
          {game.content_published_at && (
            <span className="text-xs text-ink-muted">Last published {new Date(game.content_published_at).toLocaleString("en-GB")}</span>
          )}
        </div>
      </div>

      {error && <p className="mb-4 rounded-lg bg-error-50 px-4 py-3 text-sm text-error-600">{error}</p>}

      <div className="space-y-6">
        <div className={CARD}>
          <h2 className="text-sm font-semibold text-ink">Page content</h2>
          {/* ADR-140 decision 7: primary brand only, and only once published. */}
          <p className="mb-4 mt-1 text-sm text-ink-muted">
            Shown on this game&apos;s page on the main PekanGame storefront only, under the reviews, once published. A draft stays hidden.
            Write in Malay and keep gaming terms in English. Plain text; a blank line starts a new paragraph.
          </p>

          <div className="space-y-4">
            <div>
              <Label htmlFor="content_summary">Summary ({content.summary.length}/{SUMMARY_MAX})</Label>
              <Textarea
                id="content_summary"
                maxLength={SUMMARY_MAX}
                rows={3}
                value={content.summary}
                onChange={(e) => setContent({ ...content, summary: e.target.value })}
              />
              <p className="mt-1 text-xs text-ink-muted">One or two sentences. Also the meta description (cut at about 155 characters), the AI summary and the llms.txt line.</p>
            </div>

            <div>
              <Label htmlFor="content_about">About ({content.about.length}/{ABOUT_MAX})</Label>
              <Textarea
                id="content_about"
                maxLength={ABOUT_MAX}
                rows={9}
                value={content.about}
                onChange={(e) => setContent({ ...content, about: e.target.value })}
              />
            </div>

            <div>
              <div className="mb-2 flex items-center justify-between">
                <Label>FAQ ({content.faq.length}/{FAQ_MAX_ITEMS})</Label>
                <Button
                  type="button"
                  variant="outlined"
                  disabled={content.faq.length >= FAQ_MAX_ITEMS}
                  onClick={() => setContent({ ...content, faq: [...content.faq, { question: "", answer: "" }] })}
                >
                  Add question
                </Button>
              </div>
              {content.faq.length === 0 && <p className="text-sm text-ink-muted">No questions yet.</p>}
              <div className="space-y-3">
                {content.faq.map((item, index) => (
                  <div key={index} className="rounded-xl border border-border p-4">
                    <Label htmlFor={`faq_question_${index}`}>Question ({item.question.length}/{QUESTION_MAX})</Label>
                    <Input
                      id={`faq_question_${index}`}
                      maxLength={QUESTION_MAX}
                      value={item.question}
                      onChange={(e) => setFaqItem(index, { question: e.target.value })}
                    />
                    <div className="mt-3">
                      <Label htmlFor={`faq_answer_${index}`}>Answer ({item.answer.length}/{ANSWER_MAX})</Label>
                      <Textarea
                        id={`faq_answer_${index}`}
                        maxLength={ANSWER_MAX}
                        rows={3}
                        value={item.answer}
                        onChange={(e) => setFaqItem(index, { answer: e.target.value })}
                      />
                    </div>
                    <div className="mt-3 flex justify-end">
                      <Button
                        type="button"
                        variant="outlined"
                        onClick={() => setContent({ ...content, faq: content.faq.filter((_, i) => i !== index) })}
                      >
                        Remove
                      </Button>
                    </div>
                  </div>
                ))}
              </div>
            </div>

            <div className="flex items-center justify-between border-t border-border pt-4">
              <div>
                <span className="text-sm text-ink">Published</span>
                <p className="text-xs text-ink-muted">Off keeps it a draft. Saving published content changes the sitemap date only when the text changed.</p>
              </div>
              <Switch checked={content.published} onChange={(v) => setContent({ ...content, published: v })} ariaLabel="Published" />
            </div>
          </div>

          <div className="mt-5 flex justify-end">
            <Button type="button" onClick={handleSaveContent} disabled={savingContent}>
              {savingContent ? "Saving…" : "Save content"}
            </Button>
          </div>
        </div>

        <div className={CARD}>
          <h2 className="text-sm font-semibold text-ink">Meta overrides</h2>
          {/* ADR-120 decision 5: per-game SEO is shared by every brand, so it's for exceptions. */}
          <p className="mb-4 mt-1 text-sm text-ink-muted">
            Leave blank to use the summary, then the Meta Templates. Shown on every brand&apos;s storefront — use <code>{"{store_name}"}</code> (and <code>{"{game_name}"}</code>) rather than a store name.
          </p>
          <div className="space-y-4">
            <div>
              <Label htmlFor="seo_title">SEO title ({(game.seo_title ?? "").length}/70)</Label>
              <Input id="seo_title" maxLength={70} value={game.seo_title ?? ""} onChange={(e) => setGame({ ...game, seo_title: e.target.value })} />
            </div>
            <div>
              <Label htmlFor="seo_description">SEO description ({(game.seo_description ?? "").length}/160)</Label>
              <Textarea
                id="seo_description"
                maxLength={160}
                rows={3}
                value={game.seo_description ?? ""}
                onChange={(e) => setGame({ ...game, seo_description: e.target.value })}
              />
            </div>
          </div>
        </div>

        <div className={CARD}>
          <h2 className="mb-4 text-sm font-semibold text-ink">Open Graph Image</h2>
          <div className="flex items-end gap-3">
            <div className="flex-1">
              <Label htmlFor="seo_og_image">OG image URL</Label>
              <ImageUrlInput id="seo_og_image" value={game.seo_og_image ?? ""} onChange={(url) => setGame({ ...game, seo_og_image: url })} />
            </div>
            <Button
              type="button"
              variant="outlined"
              disabled={!game.image_url}
              onClick={() => game.image_url && setGame({ ...game, seo_og_image: game.image_url })}
            >
              Use Game Image
            </Button>
          </div>
        </div>

        <div className={CARD}>
          <h2 className="mb-4 text-sm font-semibold text-ink">Structured Data</h2>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <Label htmlFor="schema_brand">Brand</Label>
              <Input id="schema_brand" value={game.schema_brand ?? ""} onChange={(e) => setGame({ ...game, schema_brand: e.target.value })} />
            </div>
            <div>
              <Label htmlFor="schema_category">Category</Label>
              <Input id="schema_category" value={game.schema_category ?? ""} onChange={(e) => setGame({ ...game, schema_category: e.target.value })} />
            </div>
          </div>
          <div className="mt-4 flex items-center justify-between">
            <div>
              <span className="text-sm text-ink">Noindex this page</span>
              <p className="text-xs text-ink-muted">Tells search engines not to index this game&apos;s page. A noindex game is also left out of the sitemap and llms.txt.</p>
            </div>
            <Switch checked={game.no_index} onChange={(v) => setGame({ ...game, no_index: v })} ariaLabel="Noindex this page" />
          </div>
          <div className="mt-5 flex justify-end">
            <Button type="button" onClick={handleSaveSeo} disabled={savingSeo}>
              {savingSeo ? "Saving…" : "Save SEO overrides"}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}
