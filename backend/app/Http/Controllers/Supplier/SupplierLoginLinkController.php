<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Services\Supplier\Manual\SupplierLoginLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ADR-125 decisions 50-51 (ADR-124 2026-10-17 addendum, points 2-4): the
 * page behind a supplier's short login link. GET shows only the order
 * summary and a "Show login" button, so a WhatsApp link-preview fetch never
 * sees a secret; the secrets come back only from the POST, audited.
 */
class SupplierLoginLinkController extends Controller
{
    private const REVEALS_PER_LINK_PER_HOUR = 10;

    public function __construct(private readonly SupplierLoginLinkService $links) {}

    public function show(string $token): Response
    {
        $link = $this->links->find($token);

        return $link === null
            ? $this->page(['state' => 'dead'], 404)
            : $this->page(['state' => 'summary', 'link' => $link, 'token' => $token, 'fields' => $this->links->publicFields($link)]);
    }

    public function reveal(Request $request, string $token): Response
    {
        $link = $this->links->find($token);

        if ($link === null) {
            return $this->page(['state' => 'dead'], 410);
        }

        if (! RateLimiter::attempt("supplier-login-reveal:{$link->id}", self::REVEALS_PER_LINK_PER_HOUR, fn () => true, 3600)) {
            return $this->page(['state' => 'limited', 'link' => $link], 429);
        }

        $secrets = $this->links->reveal($link, $request->ip());

        return $secrets === null
            ? $this->page(['state' => 'wiped', 'link' => $link], 410)
            : $this->page(['state' => 'revealed', 'link' => $link, 'fields' => $this->links->publicFields($link), 'secrets' => $secrets]);
    }

    /** @param  array<string, mixed>  $data */
    private function page(array $data, int $status = 200): Response
    {
        return response()
            ->view('supplier-login', $data, $status)
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
