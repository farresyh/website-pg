<?php

namespace App\Services\Membership;

use App\Models\Affiliate;
use App\Models\AffiliateBranding;
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
 * when the Plunk project + `send.fixfastapp.com` sender domain were
 * provisioned (2026-08-29) — `PLUNK_FROM_EMAIL`/`PLUNK_FROM_NAME`.
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
        $this->sendView(
            $to,
            'Your verification code',
            'emails.membership-otp',
            [
                'code' => $code,
                ...$this->brandingFor($affiliate),
            ]
        );
    }

    /**
     * `Affiliate` has no `branding` relation (every existing caller —
     * BrandingController, SettingsController, GalleryImageController —
     * queries `AffiliateBranding` directly), and `AffiliateBranding`
     * carries the ADR-057 tenant `AffiliateScope`, so a plain query
     * would come back empty outside the affiliate guard (a queue job,
     * this mailer). `withoutAffiliateScope()` is the same escape hatch
     * those existing callers already use.
     */
    public function brandingFor(?Affiliate $affiliate): array
    {
        $branding = $affiliate === null
            ? null
            : AffiliateBranding::withoutAffiliateScope()->where('affiliate_id', $affiliate->id)->first();

        return [
            'storeName' => $branding?->store_name ?? 'PekanGame',
            'logoUrl' => $branding?->logo_url,
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
