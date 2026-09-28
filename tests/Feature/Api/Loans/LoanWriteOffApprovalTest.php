<?php

namespace Tests\Feature\Api\Loans;

use App\Enums\Account;
use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\WriteOff;
use App\Models\WriteOffRequest;
use App\Services\LoanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSecondApprover;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * Rule 6 (maker/checker) for write-offs: a write-off request posts nothing and leaves the loan unchanged; a different user with
 * loans.write_off approves (posting the write-off and its component snapshot) or rejects it. Self-approval only with an explicit
 * approvals.self_approve grant.
 */
class LoanWriteOffApprovalTest extends TestCase
{
    use InteractsWithRepayments;
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
    }

    public function test_a_write_off_request_is_pending_until_another_user_approves_it(): void
    {
        $loan = $this->activeLoan($this->admin, penalty: 4000);
        $loan->update(['status' => LoanStatus::Default]);
        $entries = JournalEntry::count();
        // Requested by an Admin, so rule 6 blocks the requester (the Super Admin approves their own items).
        $requester = $this->employeeWithRole($this->admin, 'admin');
        $this->actingAs($requester);

        $requestId = $this->postJson("/api/v1/loans/{$loan->id}/write-off", ['reason' => 'DEVFLOW unreachable'])->assertCreated()
            ->assertJsonPath('write_off_request.status', WriteOffRequest::PENDING)
            ->json('write_off_request.id');
        $this->assertSame($entries, JournalEntry::count(), 'a request posts nothing');
        $this->assertSame(LoanStatus::Default, $loan->fresh()->status);
        $this->assertSame(0, WriteOff::count());
        $this->postJson("/api/v1/loans/{$loan->id}/write-off")->assertUnprocessable()
            ->assertJsonValidationErrors(['loan' => 'A write-off request for this loan is already waiting for approval.']);

        $this->getJson('/api/v1/loans/write-off-requests')->assertOk()
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.can_approve', false)
            ->assertJsonPath('data.0.approve_blocked_reason', 'You initiated this transaction, so another authorised user must approve it.')
            ->assertJsonPath('data.0.outstanding.total', 134000);
        $pending = app(LoanService::class)->pendingWriteOffRequests($this->admin->company_id);
        $this->assertSame([1, 134000.0], [$pending['count'], $pending['amount']]);

        $this->postJson("/api/v1/loans/write-off-requests/{$requestId}/approve")->assertForbidden();
        $this->assertSame(LoanStatus::Default, $loan->fresh()->status);

        $approver = $this->secondApprover($this->admin);
        $this->actingAs($approver)->getJson("/api/v1/loans/{$loan->id}")->assertOk()->assertJsonPath('data.write_off_request.can_approve', true);
        $this->actingAs($approver)->postJson("/api/v1/loans/write-off-requests/{$requestId}/approve")->assertOk()
            ->assertJsonPath('write_off.amount', 134000)
            ->assertJsonPath('write_off.principal_amount', 100000)
            ->assertJsonPath('write_off.penalty_amount', 4000)
            ->assertJsonPath('write_off.interest_amount', 30000)
            ->assertJsonPath('write_off.insurance_amount', 0);
        $this->actingAs($approver)->postJson("/api/v1/loans/write-off-requests/{$requestId}/approve")->assertUnprocessable();

        $request = WriteOffRequest::sole();
        $this->assertSame([WriteOffRequest::APPROVED, $approver->id, WriteOff::sole()->id], [$request->status, $request->approved_by, $request->write_off_id]);
        $this->assertSame(LoanStatus::WrittenOff, $loan->fresh()->status);
        $this->assertSame(100000.0, $this->balance($this->admin, Account::WriteOffExpense, $this->admin->branch_id));
        $this->assertSame([$requester->id, $approver->id], [AuditLog::where('action', 'WRITE_OFF_REQUESTED')->sole()->employee_id, AuditLog::where('action', 'WRITTEN_OFF')->sole()->employee_id]);
        $this->assertSame(1, JournalEntry::where('description', 'WRITE-OFF '.$loan->loan_number)->count());
        $this->actingAs($this->admin)->getJson("/api/v1/loans/{$loan->id}")->assertOk()
            ->assertJsonPath('data.write_off.interest_amount', 30000)
            ->assertJsonPath('data.write_off_request.status', WriteOffRequest::APPROVED);
    }

    public function test_rejection_posts_nothing_and_self_approval_needs_an_explicit_grant(): void
    {
        $loan = $this->activeLoan($this->admin);
        $requester = $this->employeeWithRole($this->admin, 'admin');
        $this->actingAs($requester);
        $requestId = $this->postJson("/api/v1/loans/{$loan->id}/write-off")->assertCreated()->json('write_off_request.id');

        $this->actingAs($this->secondApprover($this->admin))->postJson("/api/v1/loans/write-off-requests/{$requestId}/reject", ['reason' => 'DEVFLOW still paying'])->assertOk();
        $this->assertSame([WriteOffRequest::REJECTED, 'DEVFLOW still paying'], [WriteOffRequest::sole()->status, WriteOffRequest::sole()->rejection_reason]);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
        $this->assertSame(0, WriteOff::count());
        $this->postJson("/api/v1/loans/write-off-requests/{$requestId}/approve")->assertUnprocessable();

        $this->actingAs($requester);
        $again = $this->postJson("/api/v1/loans/{$loan->id}/write-off")->assertCreated()->json('write_off_request.id');
        $this->grantSelfApproval($requester);
        $this->postJson("/api/v1/loans/write-off-requests/{$again}/approve")->assertOk();
        $this->assertSame(LoanStatus::WrittenOff, $loan->fresh()->status);
    }

    public function test_permissions_and_company_isolation(): void
    {
        $loan = $this->activeLoan($this->admin);
        $officer = $this->employeeWithRole($this->admin, 'loan_officer');
        $this->actingAs($officer)->postJson("/api/v1/loans/{$loan->id}/write-off")->assertForbidden();

        $this->actingAs($this->admin);
        $requestId = $this->postJson("/api/v1/loans/{$loan->id}/write-off")->assertCreated()->json('write_off_request.id');
        $this->actingAs($this->employeeWithRole($this->admin, 'finance'))->postJson("/api/v1/loans/write-off-requests/{$requestId}/approve")->assertForbidden();

        $outsider = $this->signInAdmin();
        $this->actingAs($outsider)->postJson("/api/v1/loans/write-off-requests/{$requestId}/approve")->assertNotFound();
        $this->actingAs($outsider)->getJson('/api/v1/loans/write-off-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, app(LoanService::class)->pendingWriteOffRequests($outsider->company_id)['count']);
        $this->assertSame(LoanStatus::Active, $loan->fresh()->status);
    }
}
