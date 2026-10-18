<?php

namespace App\Http\Requests\Games;

use App\Models\Game;
use App\Services\Catalog\GameFulfilmentRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * ADR-125 decisions 5/46: the one place a credential schema is edited.
 * An empty list turns the game back into a UID game. Switching kind is
 * refused while the game has packages; turning it on requires the
 * game's stored settings to suit a login game (no reseller code, no
 * player validator, manual delivery with its own subtext).
 */
class UpdateGameCredentialSchemaRequest extends FormRequest
{
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
            'credential_fields' => ['present', 'array', 'max:'.GameFulfilmentRule::MAX_FIELDS],
            ...GameFulfilmentRule::schemaRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            /** @var Game $game */
            $game = $this->route('game');
            $fields = (array) $this->input('credential_fields');
            $becomesLogin = $fields !== [];

            if ($error = GameFulfilmentRule::schemaSwitch($game, $becomesLogin)) {
                $validator->errors()->add('credential_fields', $error);

                return;
            }

            if ($becomesLogin) {
                GameFulfilmentRule::validateSchema($validator, $fields);

                foreach (GameFulfilmentRule::loginGameSettings([], $game) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }
        });
    }
}
