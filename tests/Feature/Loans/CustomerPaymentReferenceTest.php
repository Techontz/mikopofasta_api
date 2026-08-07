<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\CustomerReferenceGenerator;
use App\Domain\Organization\Services\BranchCodeGenerator;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Loan;
use App\Models\LoanApprovalStage;
use App\Models\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;

/**
 * The customer-facing payment reference — client decision D6, meeting note N4.
 *
 *     "Reference number should be generated after credit officer approves."
 *
 * Format, ruled by the client: `MF-YYYY-BRANCHCODE-000001`.
 *
 * Two identifiers now coexist and they answer different questions. The
 * APPLICATION number (`loan_number`) exists from the moment somebody applies and
 * files a case that may never become a loan. The PAYMENT reference exists only
 * once credit has approved, because until then there is nothing to pay towards.
 * Most of what is proved here is that the second never appears early, never
 * appears twice, and never stops the first from working.
 *
 * The ledger foundation, not just the loan one: the last group drives real
 * payments through the provider webhook, and a payment posts.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

describe('the format', function (): void {
    it('builds MF-YYYY-BRANCHCODE-000001', function (): void {
        $branch = Branch::query()->where('name', 'Kakonko')->sole();

        expect(app(CustomerReferenceGenerator::class)->format(1, $branch->code, 2026))
            ->toBe("MF-2026-{$branch->code}-000001");
    });

    it('numbers within a branch and a year, not globally', function (): void {
        /*
         * Both segments are already in the string. A global sequence would make
         * the branch segment decorative and would leak total loan volume to
         * anybody holding one reference.
         */
        $generator = app(CustomerReferenceGenerator::class);

        expect($generator->format(7, 'KKO', 2026))->toBe('MF-2026-KKO-000007')
            ->and($generator->format(7, 'HO', 2026))->toBe('MF-2026-HO-000007')
            ->and($generator->format(7, 'KKO', 2027))->toBe('MF-2027-KKO-000007');
    });

    it('refuses to build one for a branch with no code', function (): void {
        $branch = Branch::query()->where('name', 'Kakonko')->sole();
        $branch->forceFill(['code' => ''])->saveQuietly();

        /*
         * Refused rather than substituted. A placeholder segment would produce
         * a reference that looks quotable, gets printed, gets read aloud by a
         * customer — and belongs to no branch.
         */
        expect(fn () => app(CustomerReferenceGenerator::class)->next($branch->fresh()))
            ->toThrow(App\Domain\Loans\Exceptions\LoanApprovalException::class);
    });
});

describe('when it is issued', function (): void {
    it('is absent while the application is still being reviewed', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'approved')->assertOk();

        /*
         * Two tiers have approved and there is still no reference. Handing one
         * out here would invite payments against an application credit may yet
         * refuse.
         */
        expect($loan->fresh()->payment_reference)->toBeNull()
            ->and($loan->fresh()->status)->toBe(LoanStatus::PendingCreditReview);
    });

    it('is issued the moment head office credit approves', function (): void {
        $loan = loanAtCreditReview();
        $branch = $loan->branch;

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk();

        $loan->refresh();

        expect($loan->payment_reference)->toBe(sprintf('MF-%d-%s-000001', Date::now()->year, $branch->code))
            ->and($loan->payment_reference_issued_at)->not->toBeNull();
    });

    it('serves it on the loan resource', function (): void {
        $loan = loanAtCreditReview();

        officerAt('Kakonko', RoleName::CreditOfficer);
        $body = decide($loan, 'approved')->assertOk()->json('data');

        expect($body['paymentReference'])->toStartWith('MF-')
            ->and($body['paymentReferenceIssuedAt'])->not->toBeNull()
            // The application number is untouched and still served.
            ->and($body['loanNumber'])->toBe($loan->loan_number);
    });

    it('is not issued when credit rejects, holds or returns the file', function (): void {
        foreach (['rejected', 'held', 'returned_for_modification'] as $decision) {
            $loan = loanAtCreditReview();

            officerAt('Kakonko', RoleName::CreditOfficer);
            decide($loan, $decision, 'Not yet')->assertOk();

            expect($loan->fresh()->payment_reference)->toBeNull("decision {$decision} issued a reference");
        }
    });

    it('follows the stage flag, not the stage name', function (): void {
        /*
         * The chain is configurable. Moving the issuing flag to the branch
         * stage must move where the reference is minted, without a line of code
         * changing — which is the whole reason it is data.
         */
        LoanApprovalStage::query()->where('code', 'HEAD_OFFICE_CREDIT')->update(['issues_payment_reference' => false]);
        LoanApprovalStage::query()->where('code', 'BRANCH_MANAGER')->update(['issues_payment_reference' => true]);

        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        expect($loan->fresh()->payment_reference)->toStartWith('MF-');
    });
});

describe('issued exactly once', function (): void {
    it('keeps the first reference when the file goes round the chain again', function (): void {
        $loan = loanAtCreditReview();

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk();

        $first = $loan->fresh()->payment_reference;
        $issuedAt = $loan->fresh()->payment_reference_issued_at;

        /*
         * Returned to the officer, corrected, and sent back up the whole chain.
         * A second reference would leave the customer holding one that no
         * longer matches, while payments quoting it silently stopped arriving.
         */
        $loan->fresh()->update([
            'status' => LoanStatus::PendingCreditReview,
            'approval_stage_id' => LoanApprovalStage::query()->where('code', 'HEAD_OFFICE_CREDIT')->value('id'),
        ]);

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan->fresh(), 'approved')->assertOk();

        expect($loan->fresh()->payment_reference)->toBe($first)
            ->and($loan->fresh()->payment_reference_issued_at?->toIso8601String())
            ->toBe($issuedAt?->toIso8601String());
    });

    it('gives two loans at the same branch consecutive numbers', function (): void {
        $first = loanAtCreditReview();
        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($first, 'approved')->assertOk();

        $second = loanAtCreditReview();
        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($second, 'approved')->assertOk();

        $branch = $first->fresh()->branch;
        $year = Date::now()->year;

        expect($first->fresh()->payment_reference)->toBe("MF-{$year}-{$branch->code}-000001")
            ->and($second->fresh()->payment_reference)->toBe("MF-{$year}-{$branch->code}-000002");
    });

    it('is refused by the database if two loans ever claim the same one', function (): void {
        $loan = loanAtCreditReview();
        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk();

        $taken = $loan->fresh()->payment_reference;
        $other = loanAtCreditReview();

        // The generator is careful; the index is what makes a race a failed
        // write rather than two customers sharing a reference.
        expect(fn () => $other->update(['payment_reference' => $taken]))->toThrow(QueryException::class);
    });
});

describe('what a customer can pay with', function (): void {
    it('matches an inbound payment quoting the payment reference', function (): void {
        $loan = activeLoanWithReference();

        postPaymentWebhook([
            'reference' => $loan->payment_reference,
            'amount' => '10000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-REF-1',
        ])->assertOk()->assertJsonPath('data.status', 'allocated');

        expect(Payment::query()->where('transaction_id', 'TXN-REF-1')->sole()->loan_id)
            ->toBe($loan->getKey());
    });

    it('still matches a payment quoting the application number', function (): void {
        /*
         * Loans disbursed before D6 have no payment reference and their
         * customers were given the application number. A matcher that dropped
         * it would strand every one of them the day this deployed.
         */
        $loan = activeLoanWithReference();

        postPaymentWebhook([
            'reference' => $loan->loan_number,
            'amount' => '10000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-REF-2',
        ])->assertOk()->assertJsonPath('data.status', 'allocated');

        expect(Payment::query()->where('transaction_id', 'TXN-REF-2')->sole()->loan_id)
            ->toBe($loan->getKey());
    });

    it('leaves an unknown reference unmatched rather than guessing', function (): void {
        postPaymentWebhook([
            'reference' => 'MF-2026-XXX-999999',
            'amount' => '10000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-REF-3',
        ])->assertOk()->assertJsonPath('data.status', 'unmatched');

        expect(Payment::query()->where('transaction_id', 'TXN-REF-3')->sole()->status)
            ->toBe(PaymentStatus::Unmatched);
    });
});

describe('the branch code behind it', function (): void {
    it('gives every seeded branch a code', function (): void {
        expect(Branch::query()->whereNull('code')->orWhere('code', '')->count())->toBe(0);
    });

    it('refuses two branches the same code', function (): void {
        $code = Branch::query()->where('name', 'Kakonko')->value('code');

        expect(fn () => Branch::query()->create([
            'name' => 'Impostor Branch',
            'code' => $code,
            'phone' => '0700000123',
            'type' => App\Domain\Organization\Enums\BranchType::Main,
        ]))->toThrow(QueryException::class);
    });

    it('derives initials for a multi-word name and letters for a single word', function (): void {
        $generator = app(BranchCodeGenerator::class);

        expect($generator->forName('Kigoma Mjini Central'))->toBe('KMC')
            ->and($generator->forName('Songea'))->toBe('SON');
    });

    it('suffixes rather than colliding', function (): void {
        $generator = app(BranchCodeGenerator::class);

        Branch::query()->create([
            'name' => 'Dodoma Central',
            'code' => $generator->forName('Dodoma Central'),
            'phone' => '0700000124',
            'type' => App\Domain\Organization\Enums\BranchType::Main,
        ]);

        // "Dodoma City" derives DC as well; the second must not simply fail.
        expect($generator->forName('Dodoma City'))->toBe('DC2');
    });
});

/** A disbursed loan that carries a customer payment reference. */
function activeLoanWithReference(): Loan
{
    $loan = loanAtCreditReview();

    officerAt('Kakonko', RoleName::CreditOfficer);
    decide($loan, 'approved')->assertOk();

    $loan->refresh();
    $loan->update(['status' => LoanStatus::Active, 'disbursement_date' => Date::now()->toDateString()]);

    return $loan->fresh(['schedules', 'branch']);
}
