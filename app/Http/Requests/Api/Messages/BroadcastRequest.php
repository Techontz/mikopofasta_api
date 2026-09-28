<?php

namespace App\Http\Requests\Api\Messages;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Messages → Broadcast to a branch, zone or all staff ("audience" = "branch:ID" | "zone:ID" | "company:0").
 */
class BroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('messages.use');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'audience' => ['required', 'string', 'regex:/^(branch|zone|company):\d+$/'],
            'body' => ['required', 'string', 'max:4000'],
        ];
    }
}
