<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ADR-028 decision 4: platform-wide ops config, one singleton row.
 * `current()` is a `firstOrCreate` safety net for any environment that
 * skipped seeding. `membership_enabled` here is the global master
 * kill-switch (ADR-061 decision 4) — the per-brand toggle lives on
 * `affiliates.membership_enabled`; both must be true for Membership to
 * be live on a storefront (`Affiliate::membershipEnabledEffective()`).
 */
class PlatformSettings extends Model
{
    /** ADR-124 decision 8: the consent a credential order snapshots, until an admin edits it. */
    public const DEFAULT_CREDENTIAL_CONSENT_TEXT = 'I allow this store to use my game login only to complete this order. It is deleted once the order is finished, and I will change my password afterwards.';

    protected $table = 'platform_settings';

    protected $fillable = [
        'currency',
        'vip_spend_threshold_sen',
        'maintenance_mode',
        'maintenance_message',
        'membership_enabled',
        'whatsapp_notifications_enabled',
        'credential_close_grace_hours',
        'credential_unpaid_hours',
        'credential_max_days',
        'credential_reveal_limit_per_hour',
        'credential_consent_text',
        'topup_max_sen',
        'balance_cap_default_sen',
        'company_legal_name',
        'company_reg_no',
        'company_address',
        'partner_applications_open',
    ];

    protected $casts = [
        'vip_spend_threshold_sen' => 'integer',
        'maintenance_mode' => 'boolean',
        'membership_enabled' => 'boolean',
        'whatsapp_notifications_enabled' => 'boolean',
        'credential_close_grace_hours' => 'integer',
        'credential_unpaid_hours' => 'integer',
        'credential_max_days' => 'integer',
        'credential_reveal_limit_per_hour' => 'integer',
        'topup_max_sen' => 'integer',
        'balance_cap_default_sen' => 'integer',
        'partner_applications_open' => 'boolean',
    ];

    public static function current(): self
    {
        // Every default is given explicitly, not left to the column's
        // DB DEFAULT — Eloquent never re-fetches a server-applied default
        // after INSERT, so a freshly-created row would read back null in
        // memory (ADR-049's VIP threshold needs an int, not null; ADR-027's
        // membership_enabled needs a real false, not null, per its own
        // 2026-08-29 addendum decision 20).
        return static::query()->firstOrCreate([], [
            'currency' => 'MYR',
            'vip_spend_threshold_sen' => 500000,
            'membership_enabled' => false,
            'whatsapp_notifications_enabled' => false,
            'credential_close_grace_hours' => 24,
            'credential_unpaid_hours' => 24,
            'credential_max_days' => 14,
            'credential_reveal_limit_per_hour' => 20,
            'credential_consent_text' => self::DEFAULT_CREDENTIAL_CONSENT_TEXT,
            'topup_max_sen' => 1_000_000,
            'balance_cap_default_sen' => 10_000_000,
            'partner_applications_open' => false,
        ]);
    }
}
