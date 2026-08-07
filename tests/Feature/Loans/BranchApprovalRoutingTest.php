<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Loans\Services\BranchApprovalRouter;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchApprovalRoute;
use App\Models\Loan;
use App\Models\LoanApprovalRoute;
use App\Models\LoanApprovalStage;

/**
 * Per-branch zone routing — client decision D4, meeting note N2.
 *
 *     "Zone (Mtu wa kuview na kucomment) — depending on branches given."
 *
 * A branch WITH a zone:    Officer → Branch Manager → Zone → Credit → Finance
 * A branch WITHOUT a zone: Officer → Branch Manager → Credit → Finance
 *
 * The load-bearing rule, and the one most of this file is about, is the
 * client's instruction that configuration must not reach loans already in
 * flight: *"Existing loans already in progress must continue following the route
 * they were assigned when created. Do not silently reroute loans that are
 * already in the workflow."*
 *
 * Kakonko belongs to a zone in the seeded scaffold; loans raised there walk the
 * four-tier chain. `unzonedBranch()` below strips a branch's zone so the
 * three-tier path can be driven through the same real endpoints.
 */
beforeEach(function (): void {
    seedLoanFoundation();
});

/** Removes a branch's zone, so applications raised there skip zone review. */
function unzonedBranch(string $name = 'Missenyi'): Branch
{
    $branch = Branch::query()->where('name', $name)->sole();
    $branch->update(['zone_id' => null]);

    return $branch->fresh();
}

/** An application raised at a branch with no zone. */
function unzonedLoan(): Loan
{
    $branch = unzonedBranch();
    $loan = submittedLoan();
    $loan->customer->update(['branch_id' => $branch->getKey()]);
    $loan->update(['branch_id' => $branch->getKey()]);

    // Re-snapshot: the fixture moved the loan after it was raised, which is not
    // something the application flow can do.
    LoanApprovalRoute::query()->where('loan_id', $loan->getKey())->delete();
    app(BranchApprovalRouter::class)->snapshotFor($loan->fresh());

    return $loan->fresh(['branch']);
}

describe('a branch that belongs to a zone', function (): void {
    it('routes through zone review on the way to credit', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_zone_approval');

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');

        officerAt('Kakonko', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_finance');
    });

    it('records all three tiers on the loan route', function (): void {
        $loan = submittedLoan();

        expect(LoanApprovalRoute::query()->where('loan_id', $loan->getKey())->count())->toBe(3);
    });
});

describe('a branch with no zone', function (): void {
    it('routes straight from the branch manager to head office credit', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);

        /*
         * The client's rule 3, end to end. No zone stage is offered, skipped or
         * refused — it was never in this loan's chain.
         */
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');

        officerAt('Missenyi', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_finance');
    });

    it('carries a two-tier route, with no zone stage in it', function (): void {
        $loan = unzonedLoan();

        $codes = LoanApprovalRoute::query()
            ->where('loan_id', $loan->getKey())
            ->join('loan_approval_stages', 'loan_approval_stages.id', '=', 'loan_approval_routes.loan_approval_stage_id')
            ->orderBy('loan_approval_routes.sequence')
            ->pluck('loan_approval_stages.code')
            ->all();

        expect($codes)->toBe(['BRANCH_MANAGER', 'HEAD_OFFICE_CREDIT']);
    });

    it('shows the officer a two-tier chain on the approval panel', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);

        // Showing a zone tier the loan will never reach would tell the officer
        // their file still has a stage to clear that nothing will send it to.
        expect(collect(approvalState($loan)['chain'])->pluck('code')->all())
            ->toBe(['BRANCH_MANAGER', 'HEAD_OFFICE_CREDIT']);
    });

    it('still issues the customer payment reference at credit approval', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Missenyi', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk();

        // Batch 1 keys off the stage flag, not the stage's position, so a
        // shorter chain must not cost the borrower their reference.
        expect($loan->fresh()->payment_reference)->toStartWith('MF-');
    });
});

describe('loans already in flight', function (): void {
    it('keeps its zone tier when the branch loses its zone mid-workflow', function (): void {
        $loan = submittedLoan();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_zone_approval');

        /*
         * The branch is taken out of its zone while this loan sits with the
         * zone manager. Rerouting it now would strand a file at a stage its
         * chain no longer contains.
         */
        Branch::query()->where('name', 'Kakonko')->update(['zone_id' => null]);

        officerAt('Head Office', RoleName::ZoneManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');
    });

    it('does not gain a zone tier when the branch joins a zone mid-workflow', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');

        /*
         * The branch joins a zone while this loan waits at credit. Inserting a
         * zone review now would send the file backwards, to an approver who
         * never saw it and who is being asked about a decision already taken
         * above them.
         */
        $zoneId = Branch::query()->where('name', 'Kakonko')->value('zone_id');
        Branch::query()->where('name', 'Missenyi')->update(['zone_id' => $zoneId]);

        officerAt('Missenyi', RoleName::CreditOfficer);
        decide($loan->fresh(), 'approved')->assertOk()->assertJsonPath('data.status', 'pending_finance');
    });

    it('applies the new configuration to applications raised afterwards', function (): void {
        unzonedBranch();

        // The old loan keeps two tiers.
        $before = unzonedLoan();
        expect(LoanApprovalRoute::query()->where('loan_id', $before->getKey())->count())->toBe(2);

        // The branch joins a zone; a NEW application gets three.
        $zoneId = Branch::query()->where('name', 'Kakonko')->value('zone_id');
        Branch::query()->where('name', 'Missenyi')->update(['zone_id' => $zoneId]);

        $after = submittedLoan();

        expect(LoanApprovalRoute::query()->where('loan_id', $after->getKey())->count())->toBe(3);
    });
});

describe('every decision behaves the same on a shorter chain', function (): void {
    it('rejects', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan, 'rejected', 'Insufficient security')->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::Rejected);
    });

    it('holds and releases back to the stage that paused it', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Missenyi', RoleName::CreditOfficer);
        decide($loan, 'held', 'Awaiting payslip')->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::OnHold);

        decide($loan->fresh(), 'released')->assertOk();

        // Back to credit, not back to the start.
        expect($loan->fresh()->status)->toBe(LoanStatus::PendingCreditReview);
    });

    it('returns for modification and restarts from the loan officer', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Missenyi', RoleName::CreditOfficer);
        decide($loan, 'returned_for_modification', 'Fix the tenure')->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::ReturnedForModification);

        /*
         * The client's amendment: a corrected application always restarts from
         * the Loan Officer. On an unzoned branch that means the branch manager
         * again — the first tier of THIS loan's chain, not the institution's.
         */
        officerAt('Missenyi', RoleName::LoanOfficer);
        test()->postJson("/api/v1/loans/{$loan->id}/approval/resubmit")->assertOk();

        expect($loan->fresh()->status)->toBe(LoanStatus::PendingManagerApproval);
    });

    it('walks the whole chain again after a resubmission, still without a zone', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan, 'returned_for_modification', 'Fix it')->assertOk();

        officerAt('Missenyi', RoleName::LoanOfficer);
        test()->postJson("/api/v1/loans/{$loan->id}/approval/resubmit")->assertOk();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan->fresh(), 'approved')->assertOk()->assertJsonPath('data.status', 'pending_credit_review');
    });

    it('records every decision in the approval history', function (): void {
        $loan = unzonedLoan();

        officerAt('Missenyi', RoleName::BranchManager);
        decide($loan, 'approved')->assertOk();

        officerAt('Missenyi', RoleName::CreditOfficer);
        decide($loan, 'approved')->assertOk();

        $codes = $loan->fresh()->approvalDecisions()->pluck('stage_code')->all();

        // Two decisions, two tiers, and no zone row invented for a chain that
        // never had one.
        expect($codes)->toBe(['BRANCH_MANAGER', 'HEAD_OFFICE_CREDIT']);
    });
});

describe('configuring routing from admin', function (): void {
    it('serves the branch route with the reason each stage is in or out', function (): void {
        $branch = unzonedBranch();

        actingAsRole(RoleName::Admin);

        $body = test()->getJson("/api/v1/branches/{$branch->id}/approval-route")->assertOk()->json('data');

        $zone = collect($body['stages'])->firstWhere('code', 'ZONE_MANAGER');

        expect($zone['included'])->toBeFalse()
            ->and($zone['requiresBranchZone'])->toBeTrue()
            // Null: following the default rule, not pinned by anybody.
            ->and($zone['override'])->toBeNull();
    });

    it('lets an administrator force zone review onto a branch with no zone', function (): void {
        $branch = unzonedBranch();
        $zoneStageId = LoanApprovalStage::query()->where('code', 'ZONE_MANAGER')->value('id');

        actingAsRole(RoleName::Admin);

        test()->putJson("/api/v1/branches/{$branch->id}/approval-route", [
            'overrides' => [['stageId' => $zoneStageId, 'required' => true]],
        ])->assertOk();

        expect(app(BranchApprovalRouter::class)->routeFor($branch->fresh())->pluck('code')->all())
            ->toBe(['BRANCH_MANAGER', 'ZONE_MANAGER', 'HEAD_OFFICE_CREDIT']);
    });

    it('lets an administrator remove zone review from a branch that has a zone', function (): void {
        $branch = Branch::query()->where('name', 'Kakonko')->sole();
        $zoneStageId = LoanApprovalStage::query()->where('code', 'ZONE_MANAGER')->value('id');

        actingAsRole(RoleName::Admin);

        test()->putJson("/api/v1/branches/{$branch->id}/approval-route", [
            'overrides' => [['stageId' => $zoneStageId, 'required' => false]],
        ])->assertOk();

        expect(app(BranchApprovalRouter::class)->routeFor($branch->fresh())->pluck('code')->all())
            ->toBe(['BRANCH_MANAGER', 'HEAD_OFFICE_CREDIT']);
    });

    it('returns a stage to the default rule when the override is cleared', function (): void {
        $branch = Branch::query()->where('name', 'Kakonko')->sole();
        $zoneStageId = LoanApprovalStage::query()->where('code', 'ZONE_MANAGER')->value('id');

        actingAsRole(RoleName::Admin);

        test()->putJson("/api/v1/branches/{$branch->id}/approval-route", [
            'overrides' => [['stageId' => $zoneStageId, 'required' => false]],
        ])->assertOk();

        // Sending it back as null removes the pin; the branch has a zone, so
        // the default puts zone review back.
        test()->putJson("/api/v1/branches/{$branch->id}/approval-route", [
            'overrides' => [['stageId' => $zoneStageId, 'required' => null]],
        ])->assertOk();

        expect(BranchApprovalRoute::query()->where('branch_id', $branch->getKey())->count())->toBe(0)
            ->and(app(BranchApprovalRouter::class)->routeFor($branch->fresh())->pluck('code')->all())
            ->toBe(['BRANCH_MANAGER', 'ZONE_MANAGER', 'HEAD_OFFICE_CREDIT']);
    });

    it('audits a routing change with stage codes, not ids', function (): void {
        $branch = unzonedBranch();
        $zoneStageId = LoanApprovalStage::query()->where('code', 'ZONE_MANAGER')->value('id');

        actingAsRole(RoleName::Admin);

        test()->putJson("/api/v1/branches/{$branch->id}/approval-route", [
            'overrides' => [['stageId' => $zoneStageId, 'required' => true]],
        ])->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::BranchApprovalRouteChanged->value)
            ->sole();

        // An auditor reading this in a year should not have to join to a table
        // to learn that stage 2 was the zone.
        expect($log->after_json['overrides'])->toBe(['ZONE_MANAGER' => true]);
    });

    it('refuses to let a loan officer change routing', function (): void {
        $branch = unzonedBranch();
        $zoneStageId = LoanApprovalStage::query()->where('code', 'ZONE_MANAGER')->value('id');

        officerAt('Missenyi', RoleName::LoanOfficer);

        test()->putJson("/api/v1/branches/{$branch->id}/approval-route", [
            'overrides' => [['stageId' => $zoneStageId, 'required' => true]],
        ])->assertForbidden();
    });

    it('changes nothing about loans already raised', function (): void {
        $loan = unzonedLoan();
        $zoneStageId = LoanApprovalStage::query()->where('code', 'ZONE_MANAGER')->value('id');

        actingAsRole(RoleName::Admin);

        test()->putJson("/api/v1/branches/{$loan->branch_id}/approval-route", [
            'overrides' => [['stageId' => $zoneStageId, 'required' => true]],
        ])->assertOk();

        // Configuration is read at application time only.
        expect(LoanApprovalRoute::query()->where('loan_id', $loan->getKey())->count())->toBe(2);
    });
});

describe('loans that predate routing', function (): void {
    it('falls back to the institution chain when a loan has no snapshot', function (): void {
        $loan = submittedLoan();

        // Exactly the state of every loan raised before this batch shipped.
        LoanApprovalRoute::query()->where('loan_id', $loan->getKey())->delete();

        officerAt('Kakonko', RoleName::BranchManager);
        decide($loan->fresh(), 'approved')->assertOk()->assertJsonPath('data.status', 'pending_zone_approval');
    });
});
