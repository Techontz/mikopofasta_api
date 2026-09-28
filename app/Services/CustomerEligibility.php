<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Services\Customers\KycStatusCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Loan eligibility of a customer from the business model CUSTOMER TYPE (1) → (many) LOAN CATEGORY and KYC completion
 * (`kyc_status` = completed). Used by the Loans module.
 *
 * A customer may take only the ACTIVE loan categories (LoanCategory::scopeActive — customer type active, category assigned
 * to the customer's branch) whose customer_category_id is the customer's customer type; the amount limits are the loan
 * category's amount_from / amount_to. Customer types hold no loan rules.
 *
 * The re-borrowing freeze is reported separately (`freeze`): a customer can be eligible but frozen, and may apply
 * only when eligible AND not frozen (`can_apply`). A freeze exists only for a loan FULLY SETTLED EARLY (before its
 * maturity date) and runs from that loan's disbursement for its category's Freeze Time (LoanService::recordSettlement()).
 * It blocks every new loan of the customer until it ends; its expiry never makes a customer eligible by itself.
 */
class CustomerEligibility
{
    public const NO_CUSTOMER_TYPE = 'Assign a customer type to this customer before applying for a loan.';

    public function __construct(private KycStatusCalculator $kyc) {}

    /**
     * @return array{customer_id: int, kyc_status: string, kyc_complete: bool, eligible: bool, category: array{id: int, key: string|null, code: string|null, name: string}|null, risk_level: string|null, loan_category_ids: list<int>, checklist: list<array{key: string, label: string, required: bool, complete: bool}>, reasons: list<string>, freeze: array<string, mixed>, can_apply: bool}
     */
    public function for(Customer $customer): array
    {
        $customer->loadMissing('customerCategory');
        $category = $customer->customerCategory;
        $kycComplete = $customer->kyc_status === KycStatusCalculator::COMPLETED;

        $reasons = [];
        if ($category === null) {
            $reasons[] = self::NO_CUSTOMER_TYPE;
        } elseif (! $category->is_active || $category->trashed()) {
            $reasons[] = 'Customer type is inactive';
        }
        if (! $kycComplete) {
            $reasons[] = "Please wait for the customer's KYC to be verified!";
        }

        $freeze = $this->freeze($customer);

        return [
            'customer_id' => $customer->id,
            'kyc_status' => (string) $customer->kyc_status,
            'kyc_complete' => $kycComplete,
            'eligible' => $reasons === [],
            'category' => $category ? ['id' => $category->id, 'key' => $category->key, 'code' => $category->code, 'name' => $category->name] : null,
            'risk_level' => $category?->risk_level,
            'loan_category_ids' => $this->availableLoanCategories($customer)->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'checklist' => $this->kyc->checklist($customer),
            'reasons' => $reasons,
            'freeze' => $freeze,
            'can_apply' => $reasons === [] && ! $freeze['frozen'],
        ];
    }

    /**
     * Loan categories the customer may apply for: the active categories (at the customer's branch) of the customer's
     * customer type. Empty without a customer type.
     *
     * @return Collection<int, LoanCategory>
     */
    public function availableLoanCategories(Customer $customer): Collection
    {
        $customer->loadMissing('customerCategory');
        $type = $customer->customerCategory;

        if ($type === null) {
            return new Collection;
        }

        return LoanCategory::query()
            ->where('company_id', $customer->company_id)
            ->where('customer_category_id', $type->id)
            ->active((int) $customer->branch_id)
            ->orderBy('id')
            ->get();
    }

    /**
     * Customer type check for one loan category: the customer has a customer type, the category belongs to that customer type
     * and is active at the customer's branch (a loan category id sent manually for another type is refused).
     *
     * @return list<string>
     */
    public function loanCategoryViolations(Customer $customer, int $loanCategoryId): array
    {
        $customer->loadMissing('customerCategory');
        $type = $customer->customerCategory;

        if ($type === null) {
            return [self::NO_CUSTOMER_TYPE];
        }

        $category = LoanCategory::query()->where('company_id', $customer->company_id)->find($loanCategoryId);
        if ($category === null || (int) $category->customer_category_id !== (int) $type->id) {
            return ["This loan category is not available for the customer's customer type ({$type->name})."];
        }
        if (! $type->is_active || $type->trashed()) {
            return ["This loan category is not active for the customer's customer type ({$type->name})."];
        }
        if (! $this->availableLoanCategories($customer)->contains('id', $category->id)) {
            return ['This loan category is not available for the customer branch'];
        }

        return [];
    }

    /**
     * @throws ValidationException when the loan category does not belong to the customer's customer type
     */
    public function assertLoanCategoryAvailable(Customer $customer, int $loanCategoryId, string $errorKey = 'category_id'): void
    {
        $violations = $this->loanCategoryViolations($customer, $loanCategoryId);

        if ($violations !== []) {
            $key = $violations === [self::NO_CUSTOMER_TYPE] && in_array($errorKey, ['category_id', 'loan_category_id'], true) ? 'customer_id' : $errorKey;

            throw ValidationException::withMessages([$key => $violations]);
        }
    }

    public const FREEZE_REASON = 'Previous loan was fully settled early.';

    /**
     * Current re-borrowing freeze of the customer. Blocking = any early-settled loan of the customer whose frozen_until
     * is still in the future (timestamp compare; windows are anchored to disbursement, so old history expires by itself).
     * The "previous loan" reported is that blocking loan, otherwise the customer's most recently settled loan (closed_at),
     * so the profile always shows the latest settlement even when it did not freeze.
     *
     * @return array{status: 'frozen'|'expired'|'none', frozen: bool, reborrowing_status: 'Frozen'|'Available', reason: string|null, loan_id: int|null, loan_number: string|null, loan_category: string|null, freeze_days: int|null, freeze_started_at: string|null, frozen_until: string|null, frozen_until_label: string|null, remaining_seconds: int, checked_at: string, message: string|null, previous_loan: array<string, mixed>|null}
     */
    public function freeze(Customer $customer): array
    {
        $now = CarbonImmutable::now();
        $blocking = $customer->loans()->where('early_settlement', true)->where('frozen_until', '>', $now)->with('category')->orderByDesc('frozen_until')->first();
        $loan = $blocking ?? $customer->loans()->whereNotNull('closed_at')->with('category')->orderByDesc('closed_at')->orderByDesc('id')->first();
        $frozen = $blocking !== null;
        $status = $loan?->freezeStatus($now) ?? 'none';

        return [
            'status' => $status,
            'frozen' => $frozen,
            'reborrowing_status' => $frozen ? 'Frozen' : 'Available',
            'reason' => $status === 'none' ? null : self::FREEZE_REASON,
            'loan_id' => $loan?->id,
            'loan_number' => $loan?->loan_number,
            'loan_category' => $loan?->category?->name,
            'freeze_days' => $loan?->freeze_days,
            'freeze_started_at' => $loan?->freeze_started_at?->toIso8601String(),
            'frozen_until' => $loan?->frozen_until?->toIso8601String(),
            'frozen_until_label' => $loan?->frozen_until !== null ? self::freezeUntilLabel($loan->frozen_until) : null,
            'remaining_seconds' => $frozen ? (int) $now->diffInSeconds($loan->frozen_until, true) : 0,
            'checked_at' => $now->toIso8601String(),
            'message' => $frozen ? self::freezeMessage($loan) : null,
            'previous_loan' => $loan ? self::settlementSummary($loan, $now) : null,
        ];
    }

    /**
     * Previous loan / Disbursement Date / Expected Completion Date / Settlement Date / Early Settlement / Freeze Time /
     * Freeze Start / Freeze End / Current Freeze Status of one loan.
     *
     * @return array{id: int, loan_number: string, loan_category: string|null, disbursed_at: string|null, expected_completion_date: string|null, settled_at: string|null, early_settlement: bool|null, freeze_days: int|null, freeze_started_at: string|null, frozen_until: string|null, frozen_until_label: string|null, freeze_status: string}
     */
    public static function settlementSummary(Loan $loan, ?CarbonImmutable $now = null): array
    {
        return [
            'id' => $loan->id,
            'loan_number' => $loan->loan_number,
            'loan_category' => $loan->category?->name,
            'disbursed_at' => $loan->disbursed_at?->toIso8601String(),
            'expected_completion_date' => ($loan->expected_completion_date ?? $loan->end_date)?->toDateString(),
            'settled_at' => $loan->closed_at?->toIso8601String(),
            'early_settlement' => $loan->early_settlement,
            'freeze_days' => $loan->freeze_days,
            'freeze_started_at' => $loan->freeze_started_at?->toIso8601String(),
            'frozen_until' => $loan->frozen_until?->toIso8601String(),
            'frozen_until_label' => $loan->frozen_until !== null ? self::freezeUntilLabel($loan->frozen_until) : null,
            'freeze_status' => $loan->freezeStatus($now),
        ];
    }

    /**
     * "Customer fully settled the previous loan early. Re-borrowing is frozen until 01 October 2026 10:00." (time only
     * when the freeze does not end at midnight).
     */
    public static function freezeMessage(Loan $loan): string
    {
        return 'Customer fully settled the previous loan early. Re-borrowing is frozen until '.self::freezeUntilLabel($loan->frozen_until).'.';
    }

    /**
     * "01 October 2026" (date only; the exact end time is in frozen_until).
     */
    public static function freezeUntilLabel(mixed $until): string
    {
        return CarbonImmutable::parse($until)->format('d F Y');
    }

    /**
     * @throws ValidationException when the customer is under a re-borrowing freeze
     */
    public function assertNotFrozen(Customer $customer, string $errorKey = 'customer_id'): void
    {
        $freeze = $this->freeze($customer);

        if ($freeze['frozen']) {
            throw ValidationException::withMessages([$errorKey => $freeze['message']]);
        }
    }

    /**
     * Whether the customer may take the given loan product for the given amount.
     */
    public function allows(Customer $customer, int $loanCategoryId, ?float $amount = null): bool
    {
        return $this->violations($customer, $loanCategoryId, $amount) === [];
    }

    /**
     * @throws ValidationException
     */
    public function assertEligible(Customer $customer, ?int $loanCategoryId = null, ?float $amount = null, string $errorKey = 'customer_id'): void
    {
        $violations = $this->violations($customer, $loanCategoryId, $amount);

        if ($violations !== []) {
            throw ValidationException::withMessages([$errorKey => $violations]);
        }
    }

    /**
     * Eligibility reasons plus, for a loan category, the customer type check and the category's amount limits.
     *
     * @return list<string>
     */
    public function violations(Customer $customer, ?int $loanCategoryId = null, ?float $amount = null): array
    {
        $violations = $this->for($customer)['reasons'];

        if ($loanCategoryId === null) {
            return $violations;
        }

        $hierarchy = $this->loanCategoryViolations($customer, $loanCategoryId);
        $violations = array_values(array_unique([...$violations, ...$hierarchy]));

        $category = LoanCategory::find($loanCategoryId);
        if ($hierarchy === [] && $amount !== null && $category !== null && ($amount < (float) $category->amount_from || $amount > (float) $category->amount_to)) {
            $violations[] = "Loan amount must be between {$category->level_label}";
        }

        return $violations;
    }
}
