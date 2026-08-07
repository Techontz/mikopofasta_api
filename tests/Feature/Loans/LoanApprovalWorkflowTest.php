<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Enums\LoanApprovalDecision;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\MandateGateway;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Loan;
use App\Models\LoanApprovalStage;
use App\Support\Money;

/**
 * The approval chain the client specified, end to end.
 *
 *     Loan Officer → Branch Manager → Zone Manager → Head Office Credit → Disbursement
 *
 * with Approve, Reject, Return for Modification and Hold available at every
 * stage, and every action audited.
 *
 * Driven through the real endpoints as the real roles. A test that called the
 * action directly would prove the arithmetic of the chain and none of the thing
 * the chain actually exists for — that a different person signs off at each
 * tier, and that the system refuses when one does not.
 */
beforeEach(function (): void {
    seedLoanFoundation();
});

describe('the chain', function (): void {
    it('is seeded as branch manager, zone manager, then head office credit', function (): void {
        $chain = LoanApprovalStage::chain();

        expect($chain->pluck('code')->all())
            ->toBe(['BRANCH_MANAGER', 'ZONE_MANAGER', 'HEAD_OFFICE_CREDIT'])
            ->and($chain->pluck('loan_status')->map(fn (LoanStatus $s): string => $s->value)->all())
            ->toBe(['pending_manager_approval', 'pending_zone_approval', 'pending_credit_review']);
    });

    it('walks an application from submission to finance through every tier', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_zone_approval');

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_finance');

        // Three decisions, three tiers, three different people.
        expect($loan->fresh()->approvalDecisions()->with('decider')->get()->pluck('decided_by')->unique())
            ->toHaveCount(3);
    });

    it('generates the schedule once, when the first tier clears', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        $installments = $loan->fresh()->schedules()->count();
        expect($installments)->toBeGreaterThan(0);

        // Later tiers review the plan; they do not rebuild it.
        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'approved')->assertOk();

        expect($loan->fresh()->schedules()->count())->toBe($installments);
    });

    it('leaves the chain for finance after the last active stage', function (): void {
        $loan = loanAtCreditReview();

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::PendingFinance)
            ->and($loan->fresh()->approval_stage_id)->toBeNull();
    });

    it('skips a tier the administrator has switched off', function (): void {
        /*
         * The point of the chain being data. Deactivating zone approval must
         * reroute the next decision without a line of code changing.
         */
        LoanApprovalStage::query()->where('code', 'ZONE_MANAGER')->update(['is_active' => false]);

        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');
    });
});

describe('who may decide', function (): void {
    it('refuses an approver who does not hold the stage permission', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        // A Branch Manager holds loans.approve, which is stage one only.
        decide($loan, 'approved')->assertForbidden();

        expect($loan->fresh()->status)->toBe(LoanStatus::PendingZoneApproval);
    });

    it('refuses the officer who raised the application, at every tier', function (): void {
        $loan = submittedLoan();

        // The person who raised it turns out to hold zone approval too.
        $zoneManager = officerAt('Head Office', RoleName::ZoneManager);
        $loan->update(['created_by' => $zoneManager->getKey()]);

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        // §14: separation of duties applies to the zone tier exactly as it does
        // to the branch. Being two stages removed is not a loophole.
        test()->actingAs($zoneManager);
        decide($loan, 'approved')->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_LOAN_STATE');
    });

    it('reports the same answer the write path enforces', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::LoanOfficer);
        $state = approvalState($loan);

        expect($state['canDecide'])->toBeFalse()
            ->and($state['availableDecisions'])->toBe([])
            ->and($state['currentStage']['code'])->toBe('BRANCH_MANAGER');

        officerAt('Kakonko', RoleName::BranchManager);
        $state = approvalState($loan);

        expect($state['canDecide'])->toBeTrue()
            ->and($state['availableDecisions'])->toEqualCanonicalizing([
                'approved', 'rejected', 'returned_for_modification', 'held',
            ]);
    });

    it('lets a head-office approver decide a branch loan when granted cross-branch review', function (): void {
        /*
         * §13, and what makes the Head Office Credit tier reachable at all.
         *
         * A credit officer is branch-scoped "no exceptions", liftable only by
         * the explicit `loans.review_cross_branch` grant. Without it the last
         * tiers of the client's chain could only ever be decided by somebody
         * sitting in the originating branch, which is not what "Head Office"
         * means.
         */
        $loan = loanAtCreditReview();

        $headOffice = officerAt('Head Office', RoleName::CreditOfficer);

        // Branch-scoped by default: a head-office credit officer without the
        // grant cannot touch a Kakonko loan.
        decide($loan, 'held', 'Referred to the credit committee.')->assertForbidden();

        // Per §13/§14: an explicit per-user grant, never implied by scope.
        $headOffice->givePermissionTo(PermissionName::LoansReviewCrossBranch->value);

        decide($loan, 'held', 'Referred to the credit committee.')
            ->assertOk()
            ->assertJsonPath('data.status', 'on_hold');
    });

    it('refuses a decision on a loan that is not in the chain at all', function (): void {
        $loan = loanAtCreditReview();

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'rejected', 'Affordability could not be evidenced.')->assertOk();

        // Rejected is terminal — there is no stage left to decide at.
        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_LOAN_STATE');
    });
});

describe('reject', function (): void {
    it('requires a reason and ends the application', function (): void {
        $loan = loanAtCreditReview();
        officerAt('Kakonko', RoleName::CreditOfficer);

        decide($loan, 'rejected')->assertStatus(422)->assertJsonValidationErrors(['reason']);

        decide($loan, 'rejected', 'Employer could not be verified.')
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejectedReason', 'Employer could not be verified.');

        expect($loan->fresh()->approval_stage_id)->toBeNull();
    });
});

describe('return for modification', function (): void {
    it('sends the application back and discards the schedule it was priced on', function (): void {
        $loan = loanAtCreditReview();

        expect($loan->schedules()->count())->toBeGreaterThan(0);

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'returned_for_modification', 'Guarantor ID is illegible; please re-upload.')
            ->assertOk()
            ->assertJsonPath('data.status', 'returned_for_modification');

        $loan->refresh();

        /*
         * The officer is being asked to change something. A plan priced on the
         * terms it used to have must not survive the round trip, or the loan
         * could be disbursed against a schedule nobody approved.
         */
        expect($loan->schedules()->count())->toBe(0)
            ->and($loan->approved_by)->toBeNull()
            ->and($loan->approved_at)->toBeNull()
            ->and($loan->expected_completion_date)->toBeNull();
    });

    it('re-enters the chain from the first tier when resubmitted', function (): void {
        $loan = loanAtCreditReview();

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'returned_for_modification', 'Income evidence is out of date.')->assertOk();

        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->postJson("/api/v1/loans/{$loan->id}/approval/resubmit", ['note' => 'Fresh payslips attached.'])
            ->assertOk()
            /*
             * Back to stage ONE, not to the tier that returned it: something
             * has changed, so every approver who already cleared it cleared a
             * different application.
             */
            ->assertJsonPath('data.status', 'pending_manager_approval');

        // And the schedule is rebuilt when the branch clears it again.
        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        expect($loan->fresh()->schedules()->count())->toBeGreaterThan(0);
    });

    it('refuses a resubmission of a loan that was never returned', function (): void {
        $loan = loanAtCreditReview();

        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->postJson("/api/v1/loans/{$loan->id}/approval/resubmit")->assertStatus(409);
    });
});

describe('hold', function (): void {
    it('pauses the loan and remembers where to put it back', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'held', 'Awaiting a site visit to the business premises.')
            ->assertOk()
            ->assertJsonPath('data.status', 'on_hold');

        $loan->refresh();

        expect($loan->hold_resume_status)->toBe(LoanStatus::PendingZoneApproval)
            // The stage is KEPT: a hold costs time, not a place in the queue.
            ->and($loan->approval_stage_id)->not->toBeNull();
    });

    it('releases back to the exact stage it paused, not to the start', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'held', 'Waiting on the employer letter.')->assertOk();

        decide($loan, 'released', 'Employer letter received.')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending_zone_approval');

        expect($loan->fresh()->hold_resume_status)->toBeNull();

        // And the chain continues from there rather than restarting.
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');
    });

    it('leaves the schedule untouched while held', function (): void {
        $loan = loanAtCreditReview();
        $installments = $loan->schedules()->count();

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'held', 'Credit committee meets on Thursday.')->assertOk();

        expect($loan->fresh()->schedules()->count())->toBe($installments);
    });

    it('requires a reason', function (): void {
        $loan = submittedLoan();
        officerAt('Kakonko', RoleName::BranchManager);

        decide($loan, 'held')->assertStatus(422)->assertJsonValidationErrors(['reason']);
    });

    it('refuses to release a loan that is not on hold', function (): void {
        $loan = submittedLoan();
        officerAt('Kakonko', RoleName::BranchManager);

        decide($loan, 'released')->assertStatus(409);
    });
});

describe('the audit trail', function (): void {
    it('records every decision with its stage, its actor and its reason', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        $zone = officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'held', 'Awaiting a site visit.')->assertOk();
        decide($loan, 'released', 'Visit completed.')->assertOk();
        decide($loan, 'returned_for_modification', 'Please attach the trading licence.')->assertOk();

        $trail = $loan->fresh()->approvalDecisions()->get();

        expect($trail->pluck('decision')->map(fn (LoanApprovalDecision $d): string => $d->value)->all())
            ->toBe(['approved', 'held', 'released', 'returned_for_modification'])
            ->and($trail->pluck('stage_code')->all())
            ->toBe(['BRANCH_MANAGER', 'ZONE_MANAGER', 'ZONE_MANAGER', 'ZONE_MANAGER'])
            // Every decision that is not a plain approval carries its reason.
            ->and($trail->skip(1)->every(fn ($d): bool => $d->reason !== null))->toBeTrue()
            ->and($trail->last()->decided_by)->toBe($zone->getKey());
    });

    it('writes a distinct audit action for each kind of decision', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'held', 'Awaiting confirmation.')->assertOk();
        decide($loan, 'released', 'Confirmed.')->assertOk();

        $actions = AuditLog::query()
            ->where('auditable_type', Loan::class)
            ->where('auditable_id', $loan->getKey())
            ->pluck('action');

        /*
         * Distinct actions, not one "loan decided" with a payload to grep. "Who
         * held this loan for three weeks" has to be a query.
         */
        expect($actions)->toContain(AuditAction::LoanApprovalStageCleared->value)
            ->and($actions)->toContain(AuditAction::LoanHeld->value)
            ->and($actions)->toContain(AuditAction::LoanReleasedFromHold->value);
    });

    it('records the transition trail alongside the decision trail', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'held', 'Pending documents.')->assertOk();

        expect($loan->fresh()->statusHistory()->orderBy('id')->pluck('to_status')
            ->map(fn (LoanStatus $s): string => $s->value)->all())
            ->toBe(['draft', 'pending_manager_approval', 'pending_zone_approval', 'on_hold']);
    });
});

describe('the mandate branch', function (): void {
    it('opens the mandate when the tier before credit clears, and not before', function (): void {
        $loan = submittedLoan(product: 'SALARY_ADVANCE', category: 'PUBLIC_SERVANT');

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_zone_approval');

        expect($loan->fresh()->mandates()->count())->toBe(0);

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'mandate_pending_otp');

        expect($loan->fresh()->mandates()->count())->toBe(1);
    });

    it('does not open a second mandate after a return and resubmission', function (): void {
        $loan = approvedMandateLoan();

        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->postJson("/api/v1/loans/{$loan->id}/mandate/verify-otp", ['otp' => MandateGateway::SIMULATED_OTP])->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::PendingCreditReview);

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'returned_for_modification', 'Salary figure needs restating.')->assertOk();

        officerAt('Kakonko', RoleName::LoanOfficer);
        $this->postJson("/api/v1/loans/{$loan->id}/approval/resubmit")->assertOk();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'approved')->assertOk();

        /*
         * The bank already granted the mandate. Sending the customer through
         * the OTP flow again for a mandate they have would be asking them to
         * authorise something twice.
         */
        expect($loan->fresh()->mandates()->count())->toBe(1)
            ->and($loan->fresh()->status)->toBe(LoanStatus::PendingCreditReview);
    });
});

describe('a returned loan does not reach the book', function (): void {
    it('cannot be disbursed while it sits with the officer', function (): void {
        $loan = loanAtCreditReview();

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'returned_for_modification', 'Collateral valuation missing.')->assertOk();

        officerAt('Head Office', RoleName::Finance);
        $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])
            ->assertStatus(409);

        expect(Money::of($loan->fresh()->principal_amount)->isPositive())->toBeTrue();
    });
});
