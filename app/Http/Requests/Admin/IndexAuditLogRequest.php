<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The audit trail's filters — Settings → Audit Logs. */
final class IndexAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Action name, actor name, or the record's type.
            'search' => ['nullable', 'string', 'max:120'],

            /*
             * Free text, matching the column. §2.1 calls for an extensible
             * vocabulary and the column is a VARCHAR, so constraining this to
             * the enum would make the filter unable to find rows written by a
             * later phase that the enum has not caught up with.
             */
            'action' => ['nullable', 'string', 'max:100'],

            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            // Accepts either the short name or the fully-qualified class.
            'auditable_type' => ['nullable', 'string', 'max:150'],
            'auditable_id' => ['nullable', 'integer', 'min:1'],

            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],

            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['to.after_or_equal' => 'The end of the range cannot fall before its start.'];
    }
}
