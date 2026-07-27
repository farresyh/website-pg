<?php

namespace App\Http\Requests\Gallery;

use Illuminate\Foundation\Http\FormRequest;

/**
 * IMG-1 — drag-drop or file-picker upload. Single file per request
 * (matches the drag-drop UI's one-card-at-a-time preview); multiple
 * files just means multiple requests from the same drop, not a
 * multi-file array field here.
 */
class UploadGalleryImageRequest extends FormRequest
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
            'image' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:5120', // 5MB, in kilobytes per Laravel's `max` rule for files
            ],
        ];
    }
}
