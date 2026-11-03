<?php

namespace App\Services\CsAssistant;

use App\Models\Supplier;

/**
 * ADR-136 decisions 4 and 25: every reply is checked by Laravel before it may
 * be sent (or, in a test, before it may pass). A hit means the reply is not
 * sent and the chat goes to staff.
 */
final class CsReplyGuard
{
    /** Words that reveal cost structure; matched as whole words, any case. */
    private const DENY = ['kos', 'modal', 'margin', 'untung', 'keuntungan', 'markup', 'supplier', 'pembekal', 'harga borong', 'borong', 'profit', 'cost', 'wholesale', 'digiflazz', 'gamevion'];

    /** @return list<string> what the reply broke; empty when it is safe */
    public function violations(string $reply, string $brandHost): array
    {
        $found = [];
        $lower = mb_strtolower($reply);

        foreach ([...self::DENY, ...$this->supplierNames()] as $word) {
            if ($word !== '' && preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $lower)) {
                $found[] = "word: {$word}";
            }
        }

        if (preg_match('/\d+(?:[.,]\d+)?\s?%/', $reply)) {
            $found[] = 'percentage';
        }

        // A link: a scheme, or a host ending in a common top-level domain ("akaun.Semak" is not one).
        preg_match_all('~(?:https?://([^\s/]+))|\b((?:[a-z0-9-]+\.)+(?:com|my|net|org|space|store|shop|io|co|gg|app|xyz|id|sg|me|link|ly))\b~i', $reply, $m);
        $m[1] = array_filter([...$m[1], ...$m[2]]);
        foreach (array_unique($m[1]) as $host) {
            $host = mb_strtolower((string) preg_replace(['/^www\./i', '/:\d+$/'], '', $host));
            if (! in_array($host, $this->allowedHosts($brandHost), true)) {
                $found[] = "link: {$host}";
            }
        }

        return array_values(array_unique($found));
    }

    /** The brand's own storefront and the reseller application (ADR-133). @return list<string> */
    private function allowedHosts(string $brandHost): array
    {
        return array_map(
            fn (string $h) => mb_strtolower((string) preg_replace(['/^www\./i', '/:\d+$/'], '', $h)),
            array_filter([$brandHost, (string) parse_url((string) config('services.onboarding.url'), PHP_URL_HOST)]),
        );
    }

    /** @return list<string> */
    private function supplierNames(): array
    {
        return Supplier::query()->get(['name', 'slug'])
            ->flatMap(fn (Supplier $s) => [mb_strtolower((string) $s->name), mb_strtolower((string) $s->slug)])
            ->filter(fn (string $n) => mb_strlen($n) >= 3)
            ->unique()->values()->all();
    }
}
