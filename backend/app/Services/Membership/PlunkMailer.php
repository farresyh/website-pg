<?php

namespace App\Services\Membership;

use App\Models\Affiliate;
use App\Support\BrandIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Http;

/**
 * ADR-027's 2026-08-29 addendum, decisions 27/29: sends the OTP code
 * via Plunk's transactional email API — built against Plunk's own real
 * API reference (docs.useplunk.com/api-reference/overview, confirmed
 * live 2026-08-29: `POST {base_url}/v1/send`, `Authorization: Bearer
 * <secret key>`, JSON body `to`/`subject`/`body`, `from` required and
 * its domain must be verified), not an assumed shape. `from` was added
 * when the Plunk project and its first sender domain were
 * provisioned (2026-08-29; `pekangame.com` and `pekangame.space` are verified now) — `PLUNK_FROM_EMAIL`/`PLUNK_FROM_NAME`.
 * Same short-timeout discipline as ChipGateway/GamevionAdapter —
 * this can be called on a customer-facing verify request, never left
 * to hang indefinitely.
 */
final class PlunkMailer
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly int $timeoutSeconds = 10,
        private readonly int $connectTimeoutSeconds = 5,
    ) {}

    public function sendOtpEmail(string $to, string $code, ?Affiliate $affiliate = null): void
    {
        $branding = $this->brandingFor($affiliate);

        // The code and the brand in the subject: the form Gmail recognises and trusts for a one-time code.
        $this->sendView(
            $to,
            "{$code} is your {$branding['storeName']} verification code",
            'emails.membership-otp',
            ['code' => $code, ...$branding]
        );
    }

    /**
     * The brand an email carries: name, logo, and the storefront origin
     * the header and buttons link to (`BrandIdentity`, ADR-068 2026-10-13
     * addendum decision 13). A null affiliate gets the platform brand.
     *
     * @return array{storeName: string, logoUrl: ?string, storeUrl: string}
     */
    public function brandingFor(?Affiliate $affiliate): array
    {
        $brand = BrandIdentity::of($affiliate?->id);

        return [
            'storeName' => $brand->name,
            'logoUrl' => $brand->logoUrl,
            'storeUrl' => $brand->origin,
        ];
    }

    /**
     * The transactional-send primitive — `POST {base_url}/v1/send` with
     * the verified sender. Every feature-specific email (membership OTP,
     * the ADR-058 affiliate set-password invite) formats its own
     * subject/body and calls this. Same short-timeout discipline as
     * ChipGateway/GamevionAdapter.
     */
    public function send(string $to, string $subject, string $body): void
    {
        try {
            $response = Http::baseUrl($this->baseUrl)
                ->withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->post('/v1/send', [
                    'from' => [
                        'name' => $this->fromName,
                        'email' => $this->fromEmail,
                    ],
                    'to' => $to,
                    'subject' => $subject,
                    'body' => $body,
                ]);
        } catch (ConnectionException $e) {
            throw new PlunkSendException("Plunk send failed: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new PlunkSendException("Plunk send failed: HTTP {$response->status()} {$response->body()}");
        }
    }

    /**
     * Send an HTML email generated from a Laravel Markdown view.
     * Uses the default mail theme without needing a full Mailable class.
     */
    public function sendView(string $to, string $subject, string $view, array $data = []): void
    {
        $body = app(Markdown::class)->render($view, $data)->toHtml();

        $this->send($to, $subject, $body);
    }
}
