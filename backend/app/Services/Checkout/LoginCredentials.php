<?php

namespace App\Services\Checkout;

use App\Models\Game;

/**
 * ADR-125 decisions 6/32 + ADR-124: a login order's form, already passed
 * by CheckoutInputValidator::validateCredentials(), split the way
 * `order_credentials` stores it. `identifier` becomes `orders.player_id`
 * (an email lowercased, so the blacklist and masking see one value).
 * Written with the order in one transaction by OrderFactory.
 */
final readonly class LoginCredentials
{
    /**
     * @param  list<array{key: string, label: string, value: string}>  $fields
     * @param  list<array{key: string, label: string}>  $secretFields
     * @param  array<string, string>  $secrets
     */
    public function __construct(
        public string $identifier,
        public array $fields,
        public array $secretFields,
        public array $secrets,
        public string $consentText,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromValidatedInput(Game $game, array $input, string $consentText): self
    {
        $identifier = '';
        $fields = [];
        $secretFields = [];
        $secrets = [];

        foreach ($game->credentialFields() as $field) {
            $value = $input[$field['key']] ?? null;
            $entry = ['key' => $field['key'], 'label' => $field['label']];

            if (! empty($field['secret'])) {
                $secretFields[] = $entry;
                if (is_string($value) && $value !== '') {
                    $secrets[$field['key']] = $value;
                }

                continue;
            }

            $value = trim((string) $value);
            if (! empty($field['is_identifier'])) {
                $value = $field['type'] === 'email' ? mb_strtolower($value) : $value;
                $identifier = $value;
            }

            if ($value !== '') {
                $fields[] = $entry + ['value' => $value];
            }
        }

        return new self($identifier, $fields, $secretFields, $secrets, $consentText);
    }
}
