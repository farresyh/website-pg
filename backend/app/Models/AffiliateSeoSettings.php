<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAffiliate;
use Illuminate\Database\Eloquent\Model;

/** ADR-029 decision 2: storefront-wide SEO defaults, templates, and pixel IDs — 1:1 with Affiliate. */
class AffiliateSeoSettings extends Model
{
    /** ADR-057: tenant-scoped to the current affiliate under the affiliate guard. */
    use BelongsToAffiliate;

    protected $table = 'affiliate_seo_settings';

    protected $fillable = [
        'affiliate_id',
        'default_meta_title',
        'default_meta_description',
        'default_og_image',
        'meta_title_template',
        'meta_description_template',
        'ga_measurement_id',
        'fb_pixel_id',
        'tiktok_pixel_id',
        'schema_organization_enabled',
        'schema_product_enabled',
        'schema_breadcrumb_enabled',
        'crawler_default_disallow_paths',
    ];

    protected $casts = [
        'schema_organization_enabled' => 'boolean',
        'schema_product_enabled' => 'boolean',
        'schema_breadcrumb_enabled' => 'boolean',
        'crawler_default_disallow_paths' => 'array',
    ];
}
