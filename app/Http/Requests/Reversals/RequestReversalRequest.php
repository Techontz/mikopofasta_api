<?php

declare(strict_types=1);

namespace App\Http\Requests\Reversals;

use App\Domain\Reversals\Enums\ReversalType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST /reversals` — raise a reversal request.
 *
 * The subject id is required and its meaning depends on `reversal_type`, so
 * the `exists` rule has to switch tables with it. Getting that wrong would
 * accept a payment id as a schedule id and fail deep inside an executor with a
 * 404 instead of a field error.
 */
final class RequestReversalRequest extends FormRequest
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
        $table = match ($this->enumType()) {
            ReversalType::Payment => 'payments',
            ReversalType::Disbursement => 'disbursement_batches',
            ReversalType::Penalty => 'loan_schedules',
            ReversalType::AdvancePayment => 'customer_advance_payments',
            ReversalType::Ledger, null => 'journal_entries',
        };

        return [
            'reversal_type' => ['required', Rule::in(ReversalType::values())],
            'subject_id' => ['required', 'integer', Rule::exists($table, 'id')],

            // The same 3-character floor the frontend's schema uses. A
            // reversal with no stated reason is unauditable by design.
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function enumType(): ?ReversalType
    {
        $value = $this->input('reversal_type');

        return is_string($value) ? ReversalType::tryFrom($value) : null;
    }
}
