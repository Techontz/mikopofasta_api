<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Loans\Engine\LoanEngine;
use App\Domain\Loans\Engine\LoanTerms;
use App\Domain\Loans\Enums\DisbursementChannel;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Enums\EMandateStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Enums\TelcoVerificationStatus;
use App\Domain\Loans\Services\LoanFeeCalculator;
use App\Domain\Loans\Services\LoanNumberGenerator;
use App\Models\CategoryProductEligibility;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;

/**
 * A loan book covering every stage of the §10 lifecycle.
 *
 * The schedules here are produced by the SAME LoanEngine the
 * approval endpoint uses — not by a parallel copy of the interest arithmetic.
 * That is the point: if the engine is wrong, the seed is wrong too, and the
 * tests that assert against both catch it. A seeder with its own maths would
 * quietly disagree with production and make every schedule assertion
 * meaningless.
 *
 * Loans are placed deterministically across the lifecycle so the loan list's
 * status filters and the origination/open-book tabs all have something to
 * show.
 */
final class LoanSeeder extends Seeder
{
    public function run(): void
    {
        $engine = app(LoanEngine::class);
        $numbers = app(LoanNumberGenerator::class);

        $officer = User::query()->where('phone', '0754000005')->first();
        $manager = User::query()->where('phone', '0754000004')->first();
        $finance = User::query()->where('phone', '0754000003')->first();
        $creditOfficer = User::query()->where('phone', '0754000006')->first();

        if ($officer === null || $manager === null || $finance === null) {
            return;
        }

        /*
         * Only customers who would actually pass the §6 eligibility gate:
         * KYC complete, active, and not awaiting or refused approval. Seeding
         * a loan for an ineligible customer would contradict the rule the
         * application endpoint enforces.
         */
        $eligible = Customer::query()
            ->with('category')
            ->where('kyc_status', 'completed')
            ->where('status', 'active')
            ->whereIn('approval_status', ['not_required', 'approved'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Customer $c): bool => $c->customer_category_id !== null);

        // Where each seeded loan should end up, in order.
        $lifecycle = [
            LoanStatus::PendingManagerApproval,
            LoanStatus::PendingCreditReview,
            LoanStatus::PendingFinance,
            LoanStatus::AwaitingDisbursement,
            LoanStatus::Rejected,
            LoanStatus::MandatePendingOtp,
            LoanStatus::PendingManagerApproval,
            LoanStatus::PendingCreditReview,
            LoanStatus::AwaitingDisbursement,
            LoanStatus::PendingFinance,
        ];

        $index = 0;

        foreach ($eligible as $customer) {
            if ($index >= count($lifecycle)) {
                break;
            }

            $product = $this->productFor($customer);

            if ($product === null) {
                continue;
            }

            $target = $lifecycle[$index];
            $schedule = $product->repaymentSchedules->first();

            if ($schedule === null) {
                continue;
            }

            $principal = $this->principalFor($product, $index);
            $tenureDays = $product->min_tenure_days;
            $appliedAt = Date::now()->subDays(60 - $index * 3);

            $loan = Loan::query()->create([
                'loan_number' => $numbers->next(),
                'customer_id' => $customer->getKey(),
                'loan_product_id' => $product->getKey(),
                'repayment_schedule_id' => $schedule->getKey(),
                'branch_id' => $customer->branch_id,
                'officer_id' => $officer->getKey(),
                'principal_amount' => $principal->toDecimalString(),
                'interest_rate_snapshot' => $product->interest_rate,
                'penalty_rate_snapshot' => $product->penalty_rate,
                'tenure_days' => $tenureDays,
                'requires_mandate_snapshot' => $product->requires_mandate,

                /*
                 * The fee snapshot, taken the same way ApplyForLoanAction takes
                 * it. This seeder builds loans directly rather than through
                 * that action, so the snapshot has to be repeated here — and it
                 * goes through LoanFeeCalculator rather than reading the
                 * `loan_fees` row inline, so there is still one definition of
                 * what a snapshot is.
                 */
                ...(app(LoanFeeCalculator::class)->snapshotFor($product) ?? []),

                'status' => LoanStatus::Draft,
                'created_by' => $officer->getKey(),
                'created_at' => $appliedAt,
            ]);

            $this->history($loan, null, LoanStatus::Draft, $officer, null, $appliedAt);
            $this->advance($loan, LoanStatus::PendingManagerApproval, $officer, 'Application submitted', $appliedAt);

            if ($target !== LoanStatus::PendingManagerApproval) {
                $this->walkForward($loan, $target, $product, $schedule->frequency_days, $engine, $manager, $creditOfficer, $finance, $appliedAt);
            }

            $index++;
        }
    }

    /**
     * Walks a loan from pending approval to its target status, generating the
     * schedule at the approval step exactly as DecideLoanApprovalAction does.
     */
    private function walkForward(
        Loan $loan,
        LoanStatus $target,
        LoanProduct $product,
        int $frequencyDays,
        LoanEngine $engine,
        User $manager,
        ?User $creditOfficer,
        User $finance,
        \Carbon\CarbonImmutable $appliedAt,
    ): void {
        if ($target === LoanStatus::Rejected) {
            $this->advance($loan, LoanStatus::Rejected, $manager, 'Insufficient business history', $appliedAt->addDays(2));
            $loan->update(['rejected_reason' => 'Insufficient business history']);

            return;
        }

        // --- Manager approval: the schedule is generated here -------------
        $approvedAt = $appliedAt->addDays(2);
        $product->loadMissing('interestFormula');

        $terms = LoanTerms::create(
            principal: $loan->principal(),
            interestRate: $loan->interestRate(),
            tenureDays: $loan->tenure_days,
            frequencyDays: $frequencyDays,
            startDate: $approvedAt->startOfDay(),
            gracePeriodDays: $product->grace_period_days ?? 0,
        );

        foreach ($engine->scheduleFor($terms, $product->interestFormula->code) as $installment) {
            $loan->schedules()->create($installment->toDatabaseRow($loan->getKey()));
        }

        $loan->update([
            'approved_by' => $manager->getKey(),
            'approved_at' => $approvedAt,
            'expected_completion_date' => $terms->finalDueDate()->toDateString(),
        ]);

        if ($loan->requires_mandate_snapshot) {
            $this->advance($loan, LoanStatus::MandatePendingOtp, $manager, 'Approved by manager', $approvedAt);

            $loan->mandates()->create([
                'bank_name' => 'CRDB Bank',
                'status' => EMandateStatus::PendingOtp,
            ]);

            if ($target === LoanStatus::MandatePendingOtp) {
                return;
            }

            $loan->mandates()->latest('id')->first()?->update([
                'status' => EMandateStatus::Active,
                'otp_reference' => 'OTP-SEEDED',
                'verified_at' => $approvedAt->addDay(),
            ]);

            $this->advance($loan, LoanStatus::MandateActive, $manager, 'Mandate verified', $approvedAt->addDay());
            $this->advance($loan, LoanStatus::PendingCreditReview, $manager, 'Mandate active', $approvedAt->addDay());
        } else {
            $this->advance($loan, LoanStatus::PendingCreditReview, $manager, 'Approved by manager', $approvedAt);
        }

        if ($target === LoanStatus::PendingCreditReview) {
            return;
        }

        // --- Credit review -------------------------------------------------
        $reviewedAt = $approvedAt->addDays(3);

        $loan->telcoVerifications()->create([
            'provider' => 'vodacom',
            'request_payload' => ['phone' => $loan->customer->phone, 'nida' => $loan->customer->nida_number],
            'response_payload' => ['matched' => true],
            'status' => TelcoVerificationStatus::Success,
            'verified_at' => $reviewedAt,
        ]);

        $this->advance($loan, LoanStatus::PendingFinance, $creditOfficer ?? $manager, 'Telco verification passed', $reviewedAt);

        if ($target === LoanStatus::PendingFinance) {
            return;
        }

        // --- Finance prepares the batch ------------------------------------
        $preparedAt = $reviewedAt->addDay();

        $loan->disbursementBatches()->create([
            'batch_reference' => 'VODA'.$loan->getKey(),
            'attempt_number' => 1,
            'channel' => DisbursementChannel::Vodacom,
            'status' => DisbursementStatus::Pending,
            'requested_by' => $finance->getKey(),
            'requested_at' => $preparedAt,
        ]);

        $this->advance($loan, LoanStatus::AwaitingDisbursement, $finance, 'Disbursement batch prepared', $preparedAt);

        /*
         * The book stops here on purpose. §6: "No ledger entry exists until a
         * disbursement batch reaches success" — and the ledger is Phase 6, so
         * no seeded loan is activated. Activating one would put an `active`
         * loan on the book with no corresponding Dr Loan Receivable / Cr
         * Principal entry behind it.
         */
    }

    private function advance(
        Loan $loan,
        LoanStatus $to,
        ?User $actor,
        ?string $reason,
        \Carbon\CarbonImmutable $at,
    ): void {
        $from = $loan->status;
        $loan->update(['status' => $to]);
        $this->history($loan, $from, $to, $actor, $reason, $at);
    }

    private function history(
        Loan $loan,
        ?LoanStatus $from,
        LoanStatus $to,
        ?User $actor,
        ?string $reason,
        \Carbon\CarbonImmutable $at,
    ): void {
        $loan->statusHistory()->create([
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $actor?->getKey(),
            'reason' => $reason,
            'created_at' => $at,
        ]);
    }

    /**
     * A product this customer's category is actually eligible for — the same
     * §2.3 pivot the application endpoint consults.
     */
    private function productFor(Customer $customer): ?LoanProduct
    {
        $productId = CategoryProductEligibility::query()
            ->where('customer_category_id', $customer->customer_category_id)
            ->value('loan_product_id');

        if ($productId === null) {
            return null;
        }

        return LoanProduct::query()->with(['repaymentSchedules', 'interestFormula'])->find($productId);
    }

    /**
     * A principal comfortably inside the product's own limits — varied enough
     * that the seeded schedules are not all identical.
     */
    private function principalFor(LoanProduct $product, int $index): Money
    {
        $min = $product->minAmountMoney();
        $max = $product->maxAmountMoney();

        $span = $max->subtract($min);
        $step = $span->divide(6);

        return $min->add($step->multiply(($index % 5) + 1));
    }
}
