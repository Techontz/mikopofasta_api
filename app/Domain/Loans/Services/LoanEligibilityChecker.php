<?php

declare(strict_types=1);

namespace App\Domain\Loans\Services;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Services\AccountTypeRequirementResolver;
use App\Domain\Loans\DTOs\EligibilityViolation;
use App\Enums\ActiveStatus;
use App\Models\CategoryProductEligibility;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Support\Money;
use Illuminate\Support\Facades\Date;

/**
 * The eligibility rule engine — backend spec §6, mirroring the frontend's
 * `checkLoanApplication` gate for gate.
 *
 * Every threshold comes from the LoanProduct row or the category eligibility
 * pivot; nothing is hardcoded (§6). Returns ALL violations rather than the
 * first, so an officer sees everything wrong with an application at once
 * instead of fixing them one refusal at a time.
 */
final class LoanEligibilityChecker
{
    public function __construct(
        /*
         * How many guarantors this customer needs is CONFIGURATION, not a
         * constant here.
         *
         * `account_type_requirements.min_guarantors` already existed, was
         * already enforced at registration by RegisterCustomerRequest, and is
         * now editable at Administration → Registration & Eligibility. This
         * engine held its own hardcoded 1 alongside it — two answers to one
         * question, agreeing only by coincidence. The moment an administrator
         * raised the column, registration would demand two guarantors and the
         * loan gate would still accept one, and the loan gate is the one that
         * decides lending.
         *
         * The resolver falls back to the default profile for an account type
         * with no rules of its own, and that default is created by
         * ProductionSeeder — so there is always a row to read.
         */
        private readonly AccountTypeRequirementResolver $requirements,
    ) {}

    /**
     * @param list<Loan> $customerLoans every loan this customer holds
     * @return list<EligibilityViolation>
     */
    public function check(
        Customer $customer,
        LoanProduct $product,
        int $repaymentScheduleId,
        Money $principalAmount,
        int $tenureDays,
        array $customerLoans,
        int $guarantorCount,
    ): array {
        $violations = [];

        // --- Customer gates (§9) -------------------------------------------
        if (! $customer->kyc_status->isComplete()) {
            $violations[] = new EligibilityViolation('KYC_INCOMPLETE', 'Customer KYC is not complete.');
        }

        if ($customer->status === CustomerStatus::Frozen) {
            $violations[] = new EligibilityViolation(
                'CUSTOMER_FROZEN',
                'Customer account is frozen and cannot take new loans.',
            );
        }

        if ($customer->status === CustomerStatus::Suspended) {
            $violations[] = new EligibilityViolation('CUSTOMER_SUSPENDED', 'Customer account is suspended.');
        }

        /*
         * Registration approval is a POSITIVE condition, not the absence of a
         * refusal.
         *
         * This used to fire only on `pending` and `rejected`, which let
         * `not_required` through — and that was the value almost every
         * registration carried, so a completed face scan was the last thing
         * standing between a new customer and a loan. A manager's approval is
         * now required to be on the record.
         *
         * The two existing codes are kept and `not_required` reports as
         * pending, which is what it now means: nobody has decided yet.
         */
        if ($customer->approval_status === CustomerApprovalStatus::Rejected) {
            $violations[] = new EligibilityViolation('CUSTOMER_REJECTED', 'Customer registration was rejected.');
        } elseif ($customer->approval_status !== CustomerApprovalStatus::Approved) {
            $violations[] = new EligibilityViolation(
                'CUSTOMER_PENDING_APPROVAL',
                'Customer registration is still awaiting approval by a manager.',
            );
        }

        // --- Guarantors ----------------------------------------------------
        /*
         * The customer's own account type decides this. `> 0` matters: a
         * savings account type may legitimately require none, and a hardcoded
         * minimum made that unrepresentable.
         */
        $minimumGuarantors = $this->requirements->forCustomer($customer)->min_guarantors;

        if ($minimumGuarantors > 0 && $guarantorCount < $minimumGuarantors) {
            $violations[] = new EligibilityViolation(
                'GUARANTORS_REQUIRED',
                sprintf(
                    'At least %d guarantor%s required before a loan can be submitted.',
                    $minimumGuarantors,
                    $minimumGuarantors === 1 ? ' is' : 's are',
                ),
            );
        }

        // --- Product gates (§6) --------------------------------------------
        if ($product->status !== ActiveStatus::Active) {
            $violations[] = new EligibilityViolation(
                'PRODUCT_INACTIVE',
                sprintf('"%s" is not currently active.', $product->name),
            );
        }

        $rule = $this->eligibilityRule($customer, $product);

        if ($rule === null) {
            $violations[] = new EligibilityViolation(
                'CATEGORY_NOT_ELIGIBLE_FOR_PRODUCT',
                "This customer's category is not eligible for this product.",
            );
        }

        if (! $product->allowsSchedule($repaymentScheduleId)) {
            $violations[] = new EligibilityViolation(
                'SCHEDULE_NOT_SUPPORTED_BY_PRODUCT',
                "This repayment schedule isn't supported by the selected product.",
            );
        }

        // --- Amount and tenure ---------------------------------------------
        $maxAmount = $this->effectiveMaxAmount($product, $rule);

        if ($principalAmount->lessThan($product->minAmountMoney())) {
            $violations[] = new EligibilityViolation(
                'AMOUNT_BELOW_MINIMUM',
                sprintf('Below the product minimum of %s.', $product->minAmountMoney()->toDecimalString()),
            );
        }

        if ($principalAmount->greaterThan($maxAmount)) {
            $violations[] = new EligibilityViolation(
                'AMOUNT_ABOVE_MAXIMUM',
                sprintf(
                    "Exceeds the maximum of %s for this customer's category.",
                    $maxAmount->toDecimalString(),
                ),
            );
        }

        if ($tenureDays < $product->min_tenure_days || $tenureDays > $product->max_tenure_days) {
            $violations[] = new EligibilityViolation(
                'TENURE_OUT_OF_RANGE',
                sprintf(
                    'Tenure must be between %d and %d days.',
                    $product->min_tenure_days,
                    $product->max_tenure_days,
                ),
            );
        }

        // --- One open loan at a time ---------------------------------------
        $openLoans = array_values(array_filter(
            $customerLoans,
            static fn (Loan $loan): bool => $loan->isOpen(),
        ));

        if ($openLoans !== []) {
            $violations[] = new EligibilityViolation(
                'EXISTING_OPEN_LOAN',
                sprintf('Customer already has an open loan (%s).', $openLoans[0]->loan_number),
            );
        }

        // --- Post-closure cooldown (§6) ------------------------------------
        $today = Date::now()->startOfDay();

        foreach ($customerLoans as $loan) {
            if ($loan->frozen_until !== null && $loan->frozen_until->greaterThan($today)) {
                $violations[] = new EligibilityViolation(
                    'CUSTOMER_IN_COOLDOWN',
                    sprintf(
                        'Customer is in a post-closure cooldown until %s.',
                        $loan->frozen_until->toDateString(),
                    ),
                );

                break;
            }
        }

        return $violations;
    }

    /**
     * The category→product cap, which overrides the product's own maximum when
     * present. Mirrors the frontend's `effectiveMaxAmount`: the LOWER of the
     * two always wins, so an override can tighten a limit but never loosen it
     * beyond what the product itself permits.
     */
    public function effectiveMaxAmount(LoanProduct $product, ?CategoryProductEligibility $rule): Money
    {
        $productMax = $product->maxAmountMoney();

        if ($rule?->max_amount_override === null) {
            return $productMax;
        }

        return $productMax->min(Money::of((string) $rule->max_amount_override));
    }

    public function eligibilityRule(Customer $customer, LoanProduct $product): ?CategoryProductEligibility
    {
        if ($customer->customer_category_id === null) {
            return null;
        }

        return CategoryProductEligibility::query()
            ->where('customer_category_id', $customer->customer_category_id)
            ->where('loan_product_id', $product->getKey())
            ->first();
    }
}
