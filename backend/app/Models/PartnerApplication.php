<?php

namespace App\Models;

use App\Services\PartnerApplication\PartnerApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-133 decisions 2–11: a reseller application, both stages in one row. Every status change
 * goes through `PartnerApplicationService`; nothing that orders, tops up or logs in reads it.
 */
class PartnerApplication extends Model
{
    public const TYPE_COMPANY = 'company';

    public const TYPE_INDIVIDUAL = 'individual';

    protected $fillable = [
        'status', 'source', 'applicant_type',
        'owner_name', 'business_name', 'email', 'active_email', 'phone',
        'experience', 'monthly_sales_range', 'main_games', 'order_channel', 'heard_from',
        'stage1_notice_version', 'stage1_consented_at', 'email_verified_at',
        'token_hash', 'token_expires_at',
        'owner_address', 'business_address', 'tax_number',
        'stage2_notice_version', 'stage2_consented_at', 'submitted_at',
        'decided_at', 'decided_by_admin_id', 'reseller_id',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'status' => PartnerApplicationStatus::class,
        'stage1_consented_at' => 'datetime',
        'email_verified_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'stage2_consented_at' => 'datetime',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(PartnerApplicationDocument::class);
    }

    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class)->withTrashed();
    }

    public function isCompany(): bool
    {
        return $this->applicant_type === self::TYPE_COMPANY;
    }
}
