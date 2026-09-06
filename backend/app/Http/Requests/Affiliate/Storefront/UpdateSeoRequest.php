<?php

namespace App\Http\Requests\Affiliate\Storefront;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-060 PR-6: pixel IDs are the ONLY SEO field an affiliate controls
 * (ADR-060 absorb-addendum — meta templates / OG / schema toggles stay
 * admin-central).
 *
 * The value is rendered inside a `<script>` in the storefront `<head>`
 * (a `gtag()` / `fbq()` snippet), so this is a charset gate, not a
 * format check: `'); evil(); //` must never reach the page. Format
 * regexes (`G-…`, numeric, …) are deliberately NOT used — GA/FB/TikTok
 * ID shapes drift and a strict pattern is a latent support ticket.
 */
class UpdateSeoRequest extends FormRequest
{
    /** Charset only — letters, digits, dot, dash, underscore, up to 64. */
    private const PIXEL_ID = ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]*$/'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ga_measurement_id' => self::PIXEL_ID,
            'fb_pixel_id' => self::PIXEL_ID,
            'tiktok_pixel_id' => self::PIXEL_ID,
        ];
    }
}
