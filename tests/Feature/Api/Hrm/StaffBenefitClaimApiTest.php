<?php

namespace Tests\Feature\Api\Hrm;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\StaffFundWithdrawal;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Spec §27 / §49 staff benefit claims: Prepared by HR (with the recorded benefit entitlement) → Finance Review → Approved →
 * Paid from the single STAFF FUND A/C (or Rejected). Only the payment moves money.
 */
class StaffBenefitClaimApiTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private Employee $hr;

    private Employee $finance;

    private Employee $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-10 09:00:00'));
        $this->admin = $this->signInAdmin();
        $this->hr = $this->employeeWithRole('hr');
        $this->finance = $this->employeeWithRole('finance');
        $this->member = $this->employeeWithRole('loan_officer');
        $this->contribute($this->member, 100000);
    }

    private function employeeWithRole(string $role): Employee
    {
        return Employee::factory()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->company->roles()->where('key', $role)->value('id'),
        ]);
    }

    /**
     * Staff contribution actually received by the fund (as the payroll payment posts it).
     */
    private function contribute(Employee $employee, float $amount): void
    {
        app(Ledger::class)->journal($this->admin->company_id, 'Salary payment contribution', [
            ['account' => Account::StaffFundCash, 'debit' => $amount],
            ['account' => Account::StaffFund, 'employee' => $employee->id, 'credit' => $amount],
        ]);
    }

    private function balance(Account $account, ?int $employee = null): float
    {
        return app(Ledger::class)->balance($this->admin->company_id, $account, employee: $employee);
    }

    /**
     * @return array<string, mixed>
     */
    private function claimRow(int $id): array
    {
        return collect($this->actingAs($this->finance)->getJson('/api/v1/hrm/staff-fund/claims')->assertOk()->json('data'))->firstWhere('id', $id);
    }

    public function test_hr_prepares_finance_reviews_approves_and_pays_and_the_fund_decreases_only_on_payment(): void
    {
        $this->actingAs($this->hr)->getJson("/api/v1/hrm/staff-fund/entitlements/{$this->member->id}")->assertOk()
            ->assertJsonPath('data.benefit_record', 100000)->assertJsonPath('data.claimable', 100000);

        $this->actingAs($this->finance)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $this->member->id, 'amount' => 60000, 'reason' => 'Exit'])->assertForbidden();
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $this->member->id, 'amount' => 150000, 'reason' => 'Exit'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $id = $this->actingAs($this->hr)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $this->member->id, 'amount' => 60000, 'reason' => 'Exit'])
            ->assertCreated()->assertJsonPath('data.status', StaffFundWithdrawal::STATUS_PREPARED)->assertJsonPath('data.entitlement', 100000)->json('data.id');

        // The open claim reserves part of the entitlement; nothing has left the fund.
        $this->actingAs($this->hr)->getJson("/api/v1/hrm/staff-fund/entitlements/{$this->member->id}")->assertJsonPath('data.open_claims', 60000)->assertJsonPath('data.claimable', 40000);
        $this->actingAs($this->hr)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $this->member->id, 'amount' => 50000, 'reason' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertEquals(100000, $this->balance(Account::StaffFundCash));

        // HR cannot decide; with Finance permission the preparer is still blocked (rule 6), and so is the claimant.
        $this->actingAs($this->hr)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/approve")->assertForbidden();
        $this->hr->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);
        $this->actingAs($this->hr->fresh())->postJson("/api/v1/hrm/staff-fund/claims/{$id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->member->permissionOverrides()->create(['permission' => 'payroll.pay', 'granted' => true]);
        $this->actingAs($this->member->fresh())->postJson("/api/v1/hrm/staff-fund/claims/{$id}/review")->assertForbidden();

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/pay")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00:00'));
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/review")->assertOk();
        $this->assertSame(['finance_review', 'Finance Review'], [$this->claimRow($id)['status'], $this->claimRow($id)['status_label']]);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 10:00:00'));
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/approve")->assertOk();
        $this->assertEquals(100000, $this->balance(Account::StaffFundCash), 'approval moves no money');
        $this->assertTrue($this->claimRow($id)['can_pay']);

        $this->travelTo(CarbonImmutable::parse('2026-09-13 10:00:00'));
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/pay")->assertOk();
        $this->assertEquals(40000, $this->balance(Account::StaffFundCash));
        $this->assertEquals(40000, $this->balance(Account::StaffFund, $this->member->id), 'the benefit record decreases by the amount paid');

        $row = $this->claimRow($id);
        $this->assertSame(
            ['paid', 'Paid', $this->hr->full_name, '2026-09-10 09:00:00', $this->finance->full_name, '2026-09-11 10:00:00', $this->finance->full_name, '2026-09-12 10:00:00', $this->finance->full_name, '2026-09-13 10:00:00'],
            [$row['status'], $row['status_label'], $row['prepared_by'], $row['prepared_at'], $row['reviewed_by'], $row['reviewed_at'], $row['approved_by'], $row['approved_at'], $row['paid_by'], $row['paid_at']],
        );
        $this->assertSame(TransactionType::StaffFundWithdrawal, JournalEntry::where('reference', $row['journal_reference'])->sole()->transaction_type);
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/reject", ['reason' => 'late'])->assertUnprocessable();
        $this->assertEquals(60000, $this->actingAs($this->finance)->getJson('/api/v1/hrm/staff-fund')->json('data.withdrawals'));
    }

    public function test_a_claim_above_the_available_fund_cash_is_blocked_only_at_payment_and_can_be_rejected(): void
    {
        // Entitlement 100,000 but the fund lent 80,000 out: only 20,000 cash available (§27: two separate concepts).
        app(Ledger::class)->journal($this->admin->company_id, 'Staff loan disbursement', [
            ['account' => Account::StaffLoanReceivable, 'employee' => $this->member->id, 'debit' => 80000],
            ['account' => Account::StaffFundCash, 'credit' => 80000],
        ]);

        // The Super Admin may prepare and approve his own claim.
        $id = $this->actingAs($this->admin)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $this->member->id, 'amount' => 60000, 'reason' => 'Retirement'])->assertCreated()->json('data.id');
        $this->actingAs($this->admin)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/approve")->assertOk();
        $this->assertSame(StaffFundWithdrawal::STATUS_APPROVED, StaffFundWithdrawal::findOrFail($id)->status);

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/pay")->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertSame(StaffFundWithdrawal::STATUS_APPROVED, StaffFundWithdrawal::findOrFail($id)->status);
        $this->assertEquals(20000, $this->balance(Account::StaffFundCash));
        $this->assertEquals(100000, $this->balance(Account::StaffFund, $this->member->id));

        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->actingAs($this->finance)->postJson("/api/v1/hrm/staff-fund/claims/{$id}/reject", ['reason' => 'Fund liquidity'])->assertOk();
        $row = $this->claimRow($id);
        $this->assertSame(['rejected', $this->finance->full_name, 'Fund liquidity'], [$row['status'], $row['rejected_by'], $row['rejection_reason']]);
        $this->actingAs($this->hr)->getJson("/api/v1/hrm/staff-fund/entitlements/{$this->member->id}")->assertJsonPath('data.claimable', 100000);
    }

    public function test_existing_withdrawals_are_migrated_as_paid(): void
    {
        $recordedAt = '2026-08-28 11:00:00';
        $legacyId = DB::table('staff_fund_withdrawals')->insertGetId([
            'company_id' => $this->admin->company_id, 'employee_id' => $this->member->id, 'amount' => 40000, 'reason' => 'Legacy withdrawal',
            'recorded_by' => $this->finance->id, 'created_at' => $recordedAt, 'updated_at' => $recordedAt,
        ]);
        $entry = app(Ledger::class)->journal($this->admin->company_id, 'Staff fund withdrawal - legacy', [
            ['account' => Account::StaffFund, 'employee' => $this->member->id, 'debit' => 40000],
            ['account' => Account::StaffFundCash, 'credit' => 40000],
        ], StaffFundWithdrawal::findOrFail($legacyId));
        $open = $this->actingAs($this->hr)->postJson('/api/v1/hrm/staff-fund/claims', ['empl_id' => $this->member->id, 'amount' => 10000, 'reason' => 'New'])->assertCreated()->json('data.id');

        (require database_path('migrations/2026_09_16_225847_add_claim_workflow_to_staff_fund_withdrawals.php'))->backfill();

        $legacy = StaffFundWithdrawal::findOrFail($legacyId);
        $this->assertSame(
            [StaffFundWithdrawal::STATUS_PAID, $this->finance->id, $this->finance->id, $recordedAt, $recordedAt, $entry->id],
            [$legacy->status, $legacy->prepared_by, $legacy->paid_by, $legacy->prepared_at->toDateTimeString(), $legacy->paid_at->toDateTimeString(), $legacy->journal_entry_id],
        );
        $this->assertSame(StaffFundWithdrawal::STATUS_PREPARED, StaffFundWithdrawal::findOrFail($open)->status, 'claims of the new workflow are untouched');
    }
}
