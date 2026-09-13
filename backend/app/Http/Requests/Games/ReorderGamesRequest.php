<?php

namespace App\Http\Requests\Games;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GAME-6 — admin drag-drop reorder. `game_ids` is the full new order,
 * front to back; every id must already exist and appear at most once
 * (a partial/duplicate list would leave `sort_order` in a state that
 * doesn't match what the admin actually saw on screen).
 */
class ReorderGamesRequest extends FormRequest
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
            'game_ids' => ['required', 'array', 'min:1'],
            'game_ids.*' => ['required', 'integer', 'distinct', 'exists:games,id'],
        ];
    }
}
