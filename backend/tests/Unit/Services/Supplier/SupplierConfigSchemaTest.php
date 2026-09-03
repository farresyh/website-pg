<?php

namespace Tests\Unit\Services\Supplier;

use App\Services\Supplier\SupplierConfigSchema;
use PHPUnit\Framework\TestCase;

/**
 * ADR-067 decision 2: `category_whitelist` is an OPTIONAL `list` field
 * — it appears in the edit form (fieldsFor) but never gates
 * "fully configured" (missingKeys), and is coerced to a real
 * `string[]` on save (normalizeConfig).
 */
class SupplierConfigSchemaTest extends TestCase
{
    public function test_fields_for_includes_optional_fields(): void
    {
        $fields = SupplierConfigSchema::fieldsFor('digiflazz');

        $this->assertSame('list', $fields['category_whitelist']);
        $this->assertArrayHasKey('username', $fields);
    }

    public function test_missing_keys_ignores_optional_fields(): void
    {
        $config = [
            'base_url' => 'x', 'username' => 'x', 'api_key' => 'x',
            'testing' => false, 'customer_no_separator' => '',
        ];

        $this->assertSame([], SupplierConfigSchema::missingKeys('digiflazz', $config));
    }

    public function test_missing_keys_still_catches_a_required_field(): void
    {
        $this->assertContains(
            'api_key',
            SupplierConfigSchema::missingKeys('digiflazz', ['base_url' => 'x', 'username' => 'x']),
        );
    }

    public function test_normalize_config_splits_a_comma_separated_list_field(): void
    {
        $out = SupplierConfigSchema::normalizeConfig('digiflazz', [
            'category_whitelist' => 'Games, Voucher ,, ',
        ]);

        $this->assertSame(['Games', 'Voucher'], $out['category_whitelist']);
    }

    public function test_normalize_config_leaves_an_already_array_list_field_trimmed(): void
    {
        $out = SupplierConfigSchema::normalizeConfig('digiflazz', [
            'category_whitelist' => [' Games ', '', 'Data'],
        ]);

        $this->assertSame(['Games', 'Data'], $out['category_whitelist']);
    }

    public function test_normalize_config_fails_safe_to_empty_for_a_non_string_non_array(): void
    {
        $out = SupplierConfigSchema::normalizeConfig('digiflazz', ['category_whitelist' => 123]);

        $this->assertSame([], $out['category_whitelist']);
    }

    public function test_normalize_config_leaves_untouched_keys_alone(): void
    {
        $out = SupplierConfigSchema::normalizeConfig('digiflazz', ['base_url' => 'x']);

        $this->assertSame(['base_url' => 'x'], $out);
        $this->assertArrayNotHasKey('category_whitelist', $out);
    }

    /**
     * ADR-069 stress-test Q6 — a paste artifact (trailing newline in a
     * secret → every signature 401s; trailing space in a numeric text
     * field → is_numeric() false → warning never fires) must not
     * survive a save. Booleans are left alone.
     */
    public function test_normalize_config_trims_scalar_text_and_secret_fields(): void
    {
        $out = SupplierConfigSchema::normalizeConfig('digiflazz', [
            'webhook_secret' => "  sha1-secret\n",
            'low_balance_threshold' => ' 100000 ',
            'testing' => true,
        ]);

        $this->assertSame('sha1-secret', $out['webhook_secret']);
        $this->assertSame('100000', $out['low_balance_threshold']);
        $this->assertTrue($out['testing']);
    }
}
