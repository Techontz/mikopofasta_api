<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use App\Domain\Loans\Enums\LoanApprovalDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A decision taken at any stage of the approval chain.
 *
 * The reason is conditionally required, driven off the enum rather than a
 * hand-written list of decisions: everything except a clean approval must be
 * explained, and `LoanApprovalDecision::requiresReason()` is the one place that
 * says so. A second list here could disagree with it.
 */
final class ApprovalDecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $needsReason = LoanApprovalDecision::tryFrom((string) $this->input('decision'))?->requiresReason() ?? false;

        return [
            'decision' => ['required', 'string', Rule::in(LoanApprovalDecision::values())],

            /*
             * A minimum length, not just "present". "no" and "." satisfy
             * `required` and tell the applicant nothing — and this reason is
             * what an officer has to act on, or a customer has to be given.
             */
            'reason' => [$needsReason ? 'required' : 'nullable', 'string', 'min:5', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'A reason is required so the applicant and the next approver know what happened.',
            'reason.min' => 'Please give a reason of at least 5 characters.',
        ];
    }

    public function decision(): LoanApprovalDecision
    {
        return LoanApprovalDecision::from((string) $this->validated('decision'));
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) && trim($reason) !== '' ? trim($reason) : null;
    }
}
