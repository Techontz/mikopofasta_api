<?php

declare(strict_types=1);

namespace App\Http\Requests\Reversals;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /reversals/{reversalRequest}/reject` — the note is optional, because a
 * rejection can be a withdrawal by the requester, who has already said why.
 */
final class DecideReversalRequest extends FormRequest
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
        return ['note' => ['nullable', 'string', 'max:255']];
    }
}
