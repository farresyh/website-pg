<?php

namespace App\Http\Requests\Affiliate;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-060 (2026-09-06 "domain lifecycle" addendum, section D): structural
 * validation of a custom hostname. Business rules (per-affiliate cap,
 * reserved-name denylist, global uniqueness, provider attach) live in
 * `AffiliateDomainService::add()`.
 *
 * `prepareForValidation` lower-cases and strips a pasted scheme / path /
 * port so the affiliate can paste `https://shop.acme.com/` and still
 * pass; the regex then rejects an IP, a bare label, or anything that
 * still isn't a hostname.
 */
class StoreAffiliateDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $hostname = strtolower(trim((string) $this->input('hostname')));
        $hostname = preg_replace('#^https?://#', '', $hostname) ?? $hostname;
        $hostname = explode('/', $hostname, 2)[0];
        $hostname = explode(':', $hostname, 2)[0];

        $this->merge(['hostname' => $hostname]);
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public function rules(): array
    {
        return [
            'hostname' => [
                'required',
                'string',
                'max:253',
                // A real multi-label hostname: no scheme, no port, no
                // path, not an IP. `shop.acme.com`, `acme.com`, and
                // `a.b.acme.co.uk` pass; `acme`, `1.2.3.4`, `acme.com:80`
                // do not.
                'regex:/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'hostname.regex' => 'Enter a valid domain, e.g. shop.yourbrand.com.',
        ];
    }
}
