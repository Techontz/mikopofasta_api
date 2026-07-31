<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\GroupRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Add a customer to a group, or change their office.
 *
 * Only shape is checked here. Whether the customer may actually join — same
 * branch, active, not already grouped, office vacant — is GroupService's, so
 * the rule holds however the membership is created.
 */
final class StoreGroupMemberRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'customerId' => ['required', 'integer', Rule::exists('customers', 'id')->whereNull('deleted_at')],
            'role' => ['sometimes', Rule::enum(GroupRole::class)],
            'joinedAt' => ['sometimes', 'date'],
        ];
    }
}
