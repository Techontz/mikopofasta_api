<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /auth/profile — what a member of staff may change about themselves.
 *
 * The security property of this endpoint is not in what it accepts but in what
 * it cannot express. There is no rule here for `role_id`, `branch_id`,
 * `zone_id`, `status`, `base_salary`, `employment_status` or
 * `employee_number`, and `validated()` returns only what is declared — so a
 * request carrying any of them has those keys dropped before the action ever
 * runs. Privilege escalation through this route is not blocked by a check that
 * could be forgotten; it is unrepresentable.
 *
 * The route is authenticated and always acts on `$request->user()`. It takes
 * no user id, which is what makes editing somebody else's profile impossible
 * rather than merely forbidden.
 */
final class UpdateProfileRequest extends FormRequest
{
    /** Every key this endpoint will ever write. */
    public const array EDITABLE = [
        'phone',
        'email',
        'address',
        'emergencyContactName',
        'emergencyContactPhone',
        'emergencyContactRelationship',
        'nextOfKinName',
        'nextOfKinPhone',
        'nextOfKinRelationship',
        'preferredLanguage',
        'notificationPreferences',
        'timezone',
        'dateFormat',
        'numberFormat',
        'theme',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $userId = $this->user()?->getKey();

        return [
            /* Both are sign-in identifiers, so both stay unique — ignoring
               this user's own row, or saving an unchanged phone would collide
               with itself. */
            'phone' => [
                'sometimes', 'string', 'min:9', 'max:20',
                Rule::unique('users', 'phone')->ignore($userId)->whereNull('deleted_at'),
            ],
            'email' => [
                'sometimes', 'nullable', 'email', 'max:191',
                Rule::unique('users', 'email')->ignore($userId)->whereNull('deleted_at'),
            ],

            'address' => ['sometimes', 'nullable', 'string', 'max:500'],

            'emergencyContactName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'emergencyContactPhone' => ['sometimes', 'nullable', 'string', 'min:9', 'max:20'],
            'emergencyContactRelationship' => ['sometimes', 'nullable', 'string', 'max:60'],

            'nextOfKinName' => ['sometimes', 'nullable', 'string', 'max:120'],
            'nextOfKinPhone' => ['sometimes', 'nullable', 'string', 'min:9', 'max:20'],
            'nextOfKinRelationship' => ['sometimes', 'nullable', 'string', 'max:60'],

            'preferredLanguage' => ['sometimes', 'nullable', Rule::in(['en', 'sw'])],

            /*
             * Presentation only. Each is a closed set rather than free text —
             * a timezone the server cannot resolve, or a date pattern supplied
             * by a client, are both ways to turn a settings screen into an
             * injection surface.
             */
            'timezone' => ['sometimes', 'nullable', 'timezone'],
            'dateFormat' => ['sometimes', 'nullable', Rule::in(['dd/mm/yyyy', 'mm/dd/yyyy', 'yyyy-mm-dd', 'dd mmm yyyy'])],
            'numberFormat' => ['sometimes', 'nullable', Rule::in(['1,234.56', '1.234,56', '1 234.56'])],
            'theme' => ['sometimes', 'nullable', Rule::in(['light', 'dark', 'system'])],

            'notificationPreferences' => ['sometimes', 'nullable', 'array'],
            'notificationPreferences.sms' => ['sometimes', 'boolean'],
            'notificationPreferences.email' => ['sometimes', 'boolean'],
            'notificationPreferences.inApp' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => 'That phone number already belongs to another user.',
            'email.unique' => 'That email address already belongs to another user.',
            'preferredLanguage.in' => 'Choose either English or Swahili.',
        ];
    }

    /**
     * The validated payload as columns.
     *
     * Built by walking the allowlist rather than the request, so a key that is
     * not on it cannot reach the database even if a rule is added carelessly
     * later.
     *
     * @return array<string, mixed>
     */
    public function columns(): array
    {
        $map = [
            'phone' => 'phone',
            'email' => 'email',
            'address' => 'address',
            'emergencyContactName' => 'emergency_contact_name',
            'emergencyContactPhone' => 'emergency_contact_phone',
            'emergencyContactRelationship' => 'emergency_contact_relationship',
            'nextOfKinName' => 'next_of_kin_name',
            'nextOfKinPhone' => 'next_of_kin_phone',
            'nextOfKinRelationship' => 'next_of_kin_relationship',
            'preferredLanguage' => 'preferred_language',
            'notificationPreferences' => 'notification_preferences',
            'timezone' => 'timezone',
            'dateFormat' => 'date_format',
            'numberFormat' => 'number_format',
            'theme' => 'theme',
        ];

        $out = [];

        foreach (self::EDITABLE as $key) {
            if (! $this->has($key)) {
                continue;
            }

            $value = $this->validated($key);
            $out[$map[$key]] = is_string($value) && trim($value) === '' ? null : $value;
        }

        return $out;
    }
}
