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
 * HeroSlide default already uses). Three groups: search engines and
 * social-preview bots allowed (blocking them would hide the storefront
 * from Google/social link previews — the opposite of this feature's
 * purpose); AI-training bots disallowed by default (the "genuinely
 * timely... AI-scraper blocking" concern the ADR itself names) — all
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
        'Bingbot' => 'Bing',
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
    ];

    private const DISALLOWED = [
        'GPTBot' => 'OpenAI (GPT training)',
        'ChatGPT-User' => 'OpenAI (ChatGPT browsing)',
        'CCBot' => 'Common Crawl (feeds many LLM training sets)',
        'ClaudeBot' => 'Anthropic (Claude training)',
        'Google-Extended' => 'Google (Gemini/Bard training — separate from Googlebot Search above)',
        'Bytespider' => 'ByteDance (AI training)',
        'PerplexityBot' => 'Perplexity AI',
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

        foreach (self::DISALLOWED as $userAgent => $botName) {
            CrawlerRule::query()->firstOrCreate(
                ['user_agent' => $userAgent],
                ['bot_name' => $botName, 'is_allowed' => false, 'is_custom' => false, 'sort_order' => $sortOrder++],
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
