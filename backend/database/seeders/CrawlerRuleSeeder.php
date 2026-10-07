<?php

namespace Database\Seeders;

use App\Models\Affiliate;
use App\Models\AffiliateSeoSettings;
use App\Models\CrawlerRule;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * ADR-029 addendum 2 decision 14: "A curated default bot list plus an
 * 'Add Custom Rule' escape hatch" — a fresh install must never ship
 * with an empty crawler_rules table (same reasoning DatabaseSeeder's
 * HeroSlide default already uses). Every curated bot is allowed: search
 * engines, social link-preview bots, AI answer bots (they fetch a page
 * live to quote and link it — blocking them removes the store from
 * ChatGPT/Perplexity/Claude answers), and AI training crawlers (ADR-120:
 * the platform's choice is to let models learn the brand; block one from
 * `/admin/seo/crawler` if logs show abuse). Each has its own row so it
 * can be flipped individually —
 * `is_custom = false` (curated, distinct from an admin's own "Add
 * Custom Rule" entries) and editable/removable from `/admin/seo/crawler`
 * like any other row, this is a starting default, not a locked config.
 */
class CrawlerRuleSeeder extends Seeder
{
    use WithoutModelEvents;

    private const ALLOWED = [
        // Search engines
        'Googlebot' => 'Google Search',
        'Bingbot' => 'Bing (also feeds Copilot)',
        'Slurp' => 'Yahoo',
        'DuckDuckBot' => 'DuckDuckGo',
        'Baiduspider' => 'Baidu',
        'YandexBot' => 'Yandex',
        // Social link-preview bots — blocking these breaks link
        // unfurling (the shared-link preview card), not indexing.
        'facebookexternalhit' => 'Facebook/Instagram link preview',
        'Twitterbot' => 'X (Twitter) link preview',
        'LinkedInBot' => 'LinkedIn link preview',
        'WhatsApp' => 'WhatsApp link preview',
        // AI answer/search bots — fetch pages live to cite them.
        'OAI-SearchBot' => 'OpenAI (ChatGPT search index)',
        'ChatGPT-User' => 'OpenAI (ChatGPT opening a page for a user)',
        'PerplexityBot' => 'Perplexity (search index)',
        'Perplexity-User' => 'Perplexity (opening a page for a user)',
        'Claude-SearchBot' => 'Anthropic (Claude search index)',
        'Claude-User' => 'Anthropic (Claude opening a page for a user)',
        // AI training crawlers — content may be used to train models.
        'GPTBot' => 'OpenAI (model training)',
        'ClaudeBot' => 'Anthropic (model training)',
        'Google-Extended' => 'Google (Gemini training — separate from Googlebot Search)',
        'CCBot' => 'Common Crawl (feeds many training sets)',
        'Bytespider' => 'ByteDance (model training)',
    ];

    public function run(): void
    {
        $sortOrder = 0;

        // Catch-all default: allow everything not explicitly listed above.
        CrawlerRule::query()->firstOrCreate(
            ['user_agent' => '*'],
            ['bot_name' => 'All other bots', 'is_allowed' => true, 'is_custom' => false, 'sort_order' => $sortOrder++],
        );

        foreach (self::ALLOWED as $userAgent => $botName) {
            CrawlerRule::query()->firstOrCreate(
                ['user_agent' => $userAgent],
                ['bot_name' => $botName, 'is_allowed' => true, 'is_custom' => false, 'sort_order' => $sortOrder++],
            );
        }

        $this->seedDefaultDisallowPaths();
    }

    /**
     * 2026-08-22 refinement (founder request, same session): a shared
     * "disallow for every bot" list — see the migration/`robots()`
     * doc comments for why this can't just live on the `*` row.
     * `/order/status` (a specific customer's order — no SEO value,
     * a privacy concern if indexed) and `/api` (this app's own
     * Next.js route handlers, not content) are the two genuine cases
     * today; not a guessed/padded list.
     */
    private function seedDefaultDisallowPaths(): void
    {
        $affiliate = Affiliate::primary();

        $settings = AffiliateSeoSettings::query()->firstOrCreate(['affiliate_id' => $affiliate->id]);

        // Only backfill when genuinely unset — never overwrite an
        // admin's real choice (including an intentional empty list)
        // on a re-seed.
        if ($settings->crawler_default_disallow_paths === null) {
            $settings->update(['crawler_default_disallow_paths' => ['/order/status', '/api']]);
        }
    }
}
