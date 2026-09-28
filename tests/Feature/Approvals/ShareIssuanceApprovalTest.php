<?php

namespace Tests\Feature\Approvals;

use App\Enums\Account;
use App\Enums\ShareTransactionType;
use App\Models\ApprovalPolicy;
use App\Models\Capital;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\ShareHolder;
use App\Models\ShareIssuanceRequest;
use App\Models\ShareTransaction;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use App\Services\Shares\ShareRegister;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * C6 maker/checker for paid share issuances: request (pending — no contribution, journal or share movement) → a different
 * authorised user approves (posted, dated the approval date) or rejects.
 */
class ShareIssuanceApprovalTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    private ShareHolder $alpha;

    private ShareHolder $gamma;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-13 10:00:00'));
        $this->admin = $this->signInAdmin();
        $this->alpha = $this->holder('ALPHA');
        $beta = $this->holder('BETA');
        $this->gamma = $this->holder('GAMMA');

        $this->postJson('/api/v1/shares/structure', [
            'capital_basis' => 50000000, 'total_shares' => 1000, 'authorised_shares' => 2000, 'established_on' => '2026-09-01',
            'allocations' => [
                ['share_holder_id' => $this->alpha->id, 'shares' => 500, 'treatment' => 'no_cash'],
                ['share_holder_id' => $beta->id, 'shares' => 500, 'treatment' => 'no_cash'],
            ],
        ])->assertCreated();
    }

    public function test_paid_issuance_is_pending_until_another_user_approves_it(): void
    {
        $this->actingAs($this->issuer());
        $response = $this->issue(['issue_date' => '2026-09-05'])->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.amount', 12500000)
            ->assertJsonPath('data.can_approve', false)
            ->assertJsonPath('data.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE);
        $id = $response->json('data.id');
        $register = app(ShareRegister::class);

        $this->assertSame([0, 0, 1000], [Capital::count(), JournalEntry::count(), $register->issuedShares($this->admin->company_id)]);
        $this->getJson('/api/v1/shares/issuance-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pending_total', 12500000);

        $this->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->assertSame(0, JournalEntry::count());

        $this->travelTo(CarbonImmutable::parse('2026-09-14 09:00:00'));
        $approver = $this->secondApprover($this->admin);
        $this->actingAs($approver)->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($approver)->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertUnprocessable();

        $request = ShareIssuanceRequest::findOrFail($id);
        $transaction = ShareTransaction::findOrFail($request->share_transaction_id);
        $capital = Capital::findOrFail($request->capital_id);
        $entry = JournalEntry::findOrFail($capital->journal_entry_id);
        $this->assertSame([ShareTransactionType::Issuance, 250, '2026-09-14'], [$transaction->type, (int) $transaction->shares, $transaction->transacted_at->toDateString()]);
        $this->assertSame(['posted', '2026-09-14', $approver->id], [$capital->status, $entry->entry_date->toDateString(), $entry->employee_id]);
        $this->assertSame(1, JournalEntry::count(), 'posted once');
        $this->assertSame(12500000.0, app(Ledger::class)->balance($this->admin->company_id, Account::Company));
        $this->assertSame(12500000.0, app(Ledger::class)->balance($this->admin->company_id, Account::Capital));
        $this->assertSame(1250, $register->issuedShares($this->admin->company_id));
        $this->assertTrue($register->verify($this->admin->company_id)['consistent']);
    }

    public function test_rejected_issuance_posts_nothing_and_cannot_be_approved(): void
    {
        $id = $this->issue()->assertStatus(202)->json('data.id');

        $this->asApprover($this->admin, function () use ($id): void {
            $this->postJson("/api/v1/shares/issuance-requests/{$id}/reject", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
            $this->postJson("/api/v1/shares/issuance-requests/{$id}/reject", ['reason' => 'Payment not received'])->assertOk()->assertJsonPath('data.status', 'rejected');
            $this->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertUnprocessable();
        });

        $this->assertSame([0, 0, 1000], [Capital::count(), JournalEntry::count(), app(ShareRegister::class)->issuedShares($this->admin->company_id)]);
        $this->getJson('/api/v1/shares/issuance-requests?status=rejected')->assertJsonPath('data.0.rejection_reason', 'Payment not received');
    }

    public function test_bonus_and_linked_issuances_stay_single_step(): void
    {
        $this->postJson('/api/v1/shares/issuances', ['share_holder_id' => $this->gamma->id, 'type' => 'bonus_issuance', 'shares' => 10])->assertCreated();
        $this->assertSame(0, ShareIssuanceRequest::count());
        $this->assertSame(1010, app(ShareRegister::class)->issuedShares($this->admin->company_id));
    }

    public function test_initiator_needs_the_permission_and_the_policy_and_other_companies_are_not_found(): void
    {
        $issuer = $this->issuer();
        $id = $this->actingAs($issuer)->issue()->assertStatus(202)->json('data.id');

        $this->grantSelfApproval($issuer, withCompanyPolicy: false);
        $this->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertForbidden();

        $other = $this->signInAdmin();
        $this->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertNotFound();
        $this->postJson("/api/v1/shares/issuance-requests/{$id}/reject", ['reason' => 'Nope'])->assertNotFound();
        $this->getJson('/api/v1/shares/issuance-requests')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->secondApprover($other, 'finance'))->getJson('/api/v1/shares/issuance-requests')->assertForbidden();

        $this->actingAs($issuer);
        $this->allowSelfApprovalPolicy($this->admin->company_id, [ApprovalPolicy::SHARE_ISSUANCES]);
        $this->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertOk();
        $this->assertSame(1, JournalEntry::count());
    }

    public function test_the_shareholder_login_account_cannot_approve_its_own_issuance_unless_it_is_the_super_admin(): void
    {
        $holderAccount = $this->issuer();
        $this->gamma->update(['employee_id' => $holderAccount->id]);
        $id = $this->issue()->assertStatus(202)->json('data.id');

        $this->actingAs($holderAccount)->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertForbidden();
        $this->assertSame(0, JournalEntry::count());

        $superAdminAccount = $this->secondApprover($this->admin);
        $this->gamma->update(['employee_id' => $superAdminAccount->id]);
        $this->actingAs($superAdminAccount)->postJson("/api/v1/shares/issuance-requests/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, JournalEntry::count());
    }

    /**
     * A non-exempt initiator: an Admin granted shares.issue by employee override.
     */
    private function issuer(): Employee
    {
        $issuer = $this->secondApprover($this->admin, 'admin');
        $issuer->permissionOverrides()->create(['permission' => 'shares.issue', 'granted' => true]);

        return $issuer->fresh();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function issue(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/shares/issuances', $extra + ['share_holder_id' => $this->gamma->id, 'type' => 'issuance', 'shares' => 250, 'payment_treatment' => 'paid', 'pay_method' => 'CASH']);
    }

    private function holder(string $name): ShareHolder
    {
        return ShareHolder::create(['company_id' => $this->admin->company_id, 'first_name' => $name, 'last_name' => 'HOLDER', 'mobile' => '0777', 'email' => strtolower($name).'@example.com', 'date_of_birth' => '1990-01-01']);
    }
}
