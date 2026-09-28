<?php

namespace Tests\Feature\Api\Shareholders;

use App\Enums\Account;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\ShareHolder;
use App\Services\CapitalContributions;
use App\Services\Ledger;
use App\Services\Shareholders\ShareholderAccounts;
use App\Services\Shares\ShareRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * Shareholder Portal: login and forced password change, the staff boundary, own-data-only endpoints resolved from the
 * token, the public directory whitelist, and capital submitted through the two-step approval flow (rule 6).
 */
class ShareholderPortalTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Capital::DISK);
        $this->admin = $this->signInAdmin();
        Sanctum::actingAs($this->admin);
    }

    private function holder(string $name, string $mobile, ?int $companyId = null): ShareHolder
    {
        return ShareHolder::create(['company_id' => $companyId ?? $this->admin->company_id, 'first_name' => $name, 'last_name' => 'HOLDER', 'mobile' => $mobile, 'email' => strtolower($name).'@example.com', 'gender' => 'female', 'date_of_birth' => '1990-01-01']);
    }

    /**
     * A shareholder with a portal login (temporary password already changed unless $mustChange).
     *
     * @return array{0: ShareHolder, 1: Employee, 2: string}
     */
    private function portalHolder(string $name, string $mobile, bool $mustChange = false, ?Employee $admin = null): array
    {
        $holder = $this->holder($name, $mobile, $admin?->company_id);
        $result = app(ShareholderAccounts::class)->provision($holder, $admin ?? $this->admin);
        $account = $result['account'];
        if (! $mustChange) {
            $account->forceFill(['must_change_password' => false])->save();
        }

        return [$holder->fresh(), $account->fresh(), (string) $result['credentials']['temporary_password']];
    }

    private function actAs(Employee $employee): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($employee->fresh());
    }

    private function establishShares(array $allocations): void
    {
        $this->actAs($this->admin);
        $this->postJson('/api/v1/shares/structure', [
            'capital_basis' => 1000000, 'total_shares' => array_sum(array_column($allocations, 'shares')), 'authorised_shares' => 10000,
            'established_on' => now()->toDateString(), 'allocations' => $allocations,
        ])->assertCreated();
    }

    public function test_login_exposes_the_account_and_the_temporary_password_must_be_changed_first(): void
    {
        [$holder, $account, $password] = $this->portalHolder('ALPHA', '0768999201', mustChange: true);
        $other = $account->createToken('old-device')->plainTextToken;

        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['phone' => '0768999201', 'password' => $password])->assertOk()
            ->assertJsonPath('user.account_type', 'shareholder')
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('user.shareholder.id', $holder->id)
            ->json('token');
        $this->assertTrue(AuditLog::where('action', 'Auth.login')->where('employee_id', $account->id)->exists());

        $request = function (string $method, string $uri, array $data = []) use ($token) {
            $this->app['auth']->forgetGuards();

            return $this->withToken($token)->json($method, $uri, $data);
        };

        $request('GET', '/api/v1/auth/me')->assertOk()->assertJsonPath('data.shareholder.name', 'ALPHA HOLDER')->assertJsonPath('data.must_change_password', true);
        $request('GET', '/api/v1/portal/shareholder/dashboard')->assertForbidden()->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');
        $request('GET', '/api/v1/hrm/staff')->assertForbidden()->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');

        $request('POST', '/api/v1/auth/password', ['current_password' => 'wrong', 'password' => 'NewPass2026', 'password_confirmation' => 'NewPass2026'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $request('POST', '/api/v1/auth/password', ['current_password' => $password, 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $request('POST', '/api/v1/auth/password', ['current_password' => $password, 'password' => 'NewPass2026', 'password_confirmation' => 'NewPass2026'])
            ->assertOk()->assertJsonPath('data.must_change_password', false);

        $this->assertFalse($account->fresh()->must_change_password);
        $this->assertSame(1, $account->tokens()->count(), 'other sessions are signed out');
        $this->assertFalse($account->tokens()->where('name', 'old-device')->exists());
        $this->assertNotNull($other);
        $this->assertTrue(AuditLog::where('action', 'Employee.password_changed')->where('auditable_id', $account->id)->exists());

        $request('GET', '/api/v1/portal/shareholder/dashboard')->assertOk()->assertJsonPath('data.share_holder.name', 'ALPHA HOLDER');
        $request('GET', '/api/v1/hrm/staff')->assertForbidden()->assertJsonPath('error_code', 'SHAREHOLDER_ACCOUNT');
    }

    public function test_a_shareholder_account_is_refused_on_every_admin_endpoint(): void
    {
        [$holder, $account] = $this->portalHolder('ALPHA', '0768999202');
        $capital = Capital::create(['company_id' => $this->admin->company_id, 'share_holder_id' => $holder->id, 'amount' => 5000, 'pay_method' => 'CASH', 'status' => 'pending', 'recorded_by' => $this->admin->id]);
        $this->actAs($account);

        $forbidden = [
            ['GET', '/api/v1/hrm/staff'], ['GET', '/api/v1/dashboard'], ['GET', '/api/v1/customers'], ['POST', '/api/v1/loans'],
            ['POST', '/api/v1/loans/1/approve-manager'], ['POST', '/api/v1/accounting/journal/1/reverse'], ['GET', '/api/v1/accounting/journal'],
            ['PUT', "/api/v1/capital/share-holders/{$holder->id}"], ['DELETE', "/api/v1/capital/share-holders/{$holder->id}"],
            ['POST', "/api/v1/capital/capitals/{$capital->id}/approve"], ['POST', "/api/v1/capital/capitals/{$capital->id}/reject"],
            ['GET', '/api/v1/capital/share-holders'], ['GET', '/api/v1/reports/portfolio'], ['GET', '/api/v1/reports/financial/profit-loss'],
            ['GET', '/api/v1/settings/roles'], ['PUT', "/api/v1/settings/employees/{$account->id}/role"], ['GET', '/api/v1/options/employees'],
            ['GET', '/api/v1/messages/contacts'], ['PUT', '/api/v1/settings/company/password'],
        ];
        foreach ($forbidden as [$method, $uri]) {
            $this->json($method, $uri)->assertForbidden()->assertJsonPath('error_code', 'SHAREHOLDER_ACCOUNT');
        }

        $this->assertSame('pending', $capital->fresh()->status);
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_portal_endpoints_resolve_the_shareholder_from_the_token_and_return_only_own_data(): void
    {
        [$alpha, $alphaAccount] = $this->portalHolder('ALPHA', '0768999203');
        [$beta, $betaAccount] = $this->portalHolder('BETA', '0768999204');

        $betaPosted = app(CapitalContributions::class)->contribute($beta, 700000, 'CASH', null, $this->admin, 'BETA-RCPT')['capital'];
        $betaPending = Capital::create(['company_id' => $this->admin->company_id, 'share_holder_id' => $beta->id, 'amount' => 3000, 'pay_method' => 'CASH', 'status' => 'pending', 'source' => 'shareholder_portal', 'recorded_by' => $betaAccount->id, 'receipt_file' => 'capital-receipts/x.pdf']);
        Storage::disk(Capital::DISK)->put('capital-receipts/x.pdf', 'pdf');
        app(CapitalContributions::class)->contribute($alpha, 100000, 'CASH', null, $this->admin, 'ALPHA-RCPT');

        $declarationId = DB::table('dividend_declarations')->insertGetId(['company_id' => $this->admin->company_id, 'period' => '2026-06-01', 'profit_amount' => 1000, 'reinvest_percent' => 0, 'reinvest_amount' => 0, 'dividend_percent' => 100, 'dividend_amount' => 1000]);
        $betaAllocation = DB::table('dividend_allocations')->insertGetId(['company_id' => $this->admin->company_id, 'dividend_declaration_id' => $declarationId, 'share_holder_id' => $beta->id, 'share_percent' => 60, 'amount' => 600, 'paid_amount' => 600, 'status' => 'paid']);
        DB::table('dividend_allocations')->insert(['company_id' => $this->admin->company_id, 'dividend_declaration_id' => $declarationId, 'share_holder_id' => $alpha->id, 'share_percent' => 40, 'amount' => 400, 'paid_amount' => 0, 'status' => 'unpaid']);
        DB::table('dividend_payments')->insert(['company_id' => $this->admin->company_id, 'dividend_allocation_id' => $betaAllocation, 'share_holder_id' => $beta->id, 'amount' => 600, 'pay_method' => 'CASH', 'source_account' => 'dividend', 'reference' => 'BETA-DIV', 'paid_at' => now(), 'status' => 'posted']);

        $this->actAs($alphaAccount);
        $capital = $this->getJson("/api/v1/portal/shareholder/capital?share_holder_id={$beta->id}")->assertOk()->json('data');
        $this->assertSame([100000.0], array_map('floatval', array_column($capital['rows'], 'amount')));
        $this->assertSame(100000.0, (float) $capital['total_contributed']);
        $this->assertStringNotContainsString('BETA', (string) json_encode($capital));

        $dividends = $this->getJson("/api/v1/portal/shareholder/dividends?share_holder_id={$beta->id}")->assertOk()->json('data');
        $this->assertCount(1, $dividends['rows']);
        $this->assertEquals(['entitled' => 400, 'paid' => 0, 'outstanding' => 400], $dividends['totals']);
        $this->assertStringNotContainsString('BETA-DIV', (string) json_encode($dividends));

        $statement = $this->getJson('/api/v1/portal/shareholder/statement/download?from=2026-01-01&share_holder_id='.$beta->id)->assertOk()->json('data');
        $this->assertSame('ALPHA HOLDER', $statement['share_holder']['name']);
        $this->assertEquals(100000, $statement['closing_capital']);
        $this->assertStringNotContainsString('BETA', (string) json_encode($statement));
        $this->assertTrue(AuditLog::where('action', 'ShareHolder.statement_downloaded')->where('auditable_id', $alpha->id)->exists());

        $this->getJson("/api/v1/portal/shareholder/capital/{$betaPending->id}/receipt")->assertNotFound();
        $this->postJson("/api/v1/portal/shareholder/capital/{$betaPending->id}/cancel")->assertNotFound();
        $this->assertSame('pending', $betaPending->fresh()->status);
        $this->getJson('/api/v1/portal/shareholder/dashboard?share_holder_id='.$beta->id)->assertOk()
            ->assertJsonPath('data.share_holder.id', $alpha->id)
            ->assertJsonPath('data.my_capital', 100000)
            ->assertJsonPath('data.total_shareholder_capital', 800000)
            ->assertJsonPath('data.dividends.entitled', 400);

        // Profile: only safe fields change; ids and names in the body are ignored.
        $this->putJson('/api/v1/portal/shareholder/profile', ['email' => 'alpha.new@example.com', 'mobile' => '0768999299', 'first_name' => 'HACKED', 'share_holder_id' => $beta->id])->assertOk();
        $this->assertSame(['ALPHA', 'alpha.new@example.com', '0768999299'], [$alpha->fresh()->first_name, $alpha->fresh()->email, $alpha->fresh()->mobile]);
        $this->assertSame('0768999299', $alphaAccount->fresh()->phone, 'the login phone follows');
        $this->assertSame('beta@example.com', $beta->fresh()->email);
        $this->putJson('/api/v1/portal/shareholder/profile', ['email' => 'x@example.com', 'mobile' => '0768999204'])->assertUnprocessable()->assertJsonValidationErrors('share_mobile');
        $this->assertTrue(AuditLog::where('action', 'ShareHolder.profile_updated')->where('auditable_id', $alpha->id)->exists());
        $this->assertSame(700000.0, (float) $betaPosted->fresh()->amount);
    }

    public function test_directory_lists_every_holder_with_public_fields_only_and_ownership_from_the_share_register(): void
    {
        [$alpha, $alphaAccount] = $this->portalHolder('ALPHA', '0768999205');
        $beta = $this->holder('BETA', '0768999206');
        $gamma = $this->holder('GAMMA', '0768999207');
        $this->establishShares([
            ['share_holder_id' => $alpha->id, 'shares' => 250, 'treatment' => 'no_cash'],
            ['share_holder_id' => $beta->id, 'shares' => 500, 'treatment' => 'no_cash'],
            ['share_holder_id' => $gamma->id, 'shares' => 250, 'treatment' => 'no_cash'],
        ]);

        $this->actAs($alphaAccount);
        $directory = $this->getJson('/api/v1/portal/shareholder/directory')->assertOk()->json('data');
        $this->assertSame(3, $directory['totals']['shareholders']);
        $this->assertSame(1000, $directory['totals']['total_shares']);
        $this->assertCount(3, $directory['distribution']);

        $register = app(ShareRegister::class)->register((int) $this->admin->company_id)->keyBy(fn (array $row): string => $row['share_holder']->full_name);
        foreach ($directory['rows'] as $row) {
            $this->assertSame(['holder_number', 'name', 'shares', 'ownership_percent', 'capital_contributed', 'is_me'], array_keys($row), 'strict whitelist');
            $this->assertEquals($register[$row['name']]['ownership_percent'], $row['ownership_percent']);
            $this->assertSame($register[$row['name']]['shares'], $row['shares']);
        }
        $json = (string) json_encode($directory);
        foreach (['0768999206', 'beta@example.com', '1990-01-01', 'mobile', 'email', 'date_of_birth', 'passport_photo', 'gender'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertEquals(50, collect($directory['rows'])->firstWhere('name', 'BETA HOLDER')['ownership_percent']);
        $this->assertTrue(collect($directory['rows'])->firstWhere('name', 'ALPHA HOLDER')['is_me']);

        $this->getJson('/api/v1/portal/shareholder/dashboard')->assertOk()->assertJsonPath('data.my_shares', 250)->assertJsonPath('data.my_ownership_percent', 25)->assertJsonPath('data.total_company_shares', 1000);
        $this->getJson('/api/v1/portal/shareholder/share-structure')->assertOk()->assertJsonPath('data.issued_shares', 1000)->assertJsonPath('data.authorised_shares', 10000)->assertJsonPath('data.par_value', 1000);
        $this->getJson('/api/v1/portal/shareholder/shares')->assertOk()->assertJsonPath('data.shares', 250)->assertJsonCount(1, 'data.transactions');

        // Company isolation: another company's shareholders never appear.
        $otherAdmin = Employee::factory()->create(['company_id' => Company::factory()->create()->id, 'branch_id' => null]);
        $this->holder('OUTSIDER', '0768999208', $otherAdmin->company_id);
        $this->assertStringNotContainsString('OUTSIDER', (string) json_encode($this->getJson('/api/v1/portal/shareholder/directory')->json('data')));
    }

    public function test_submitted_capital_is_pending_until_another_authorised_user_approves_it_once(): void
    {
        [$holder, $account] = $this->portalHolder('ALPHA', '0768999209');
        $bank = BankAccount::create(['company_id' => $this->admin->company_id, 'name' => 'NMB']);
        $ledger = app(Ledger::class);
        $companyId = (int) $this->admin->company_id;

        $this->actAs($account);
        $this->getJson('/api/v1/portal/shareholder/bank-accounts')->assertOk()->assertExactJson(['data' => [['id' => $bank->id, 'name' => 'NMB']]]);
        $this->postJson('/api/v1/portal/shareholder/capital', ['amount' => 0, 'payment_method' => 'BANK', 'transaction_reference' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount', 'bank_account_id', 'transaction_reference']);

        $other = $this->holder('BETA', '0768999210');
        $id = $this->post('/api/v1/portal/shareholder/capital', [
            'amount' => '1000', 'payment_method' => 'BANK', 'bank_account_id' => $bank->id, 'transaction_reference' => 'DEVFLOW-TRX-1',
            'share_holder_id' => $other->id, 'receipt_file' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json', 'Idempotency-Key' => 'portal-capital-0001'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.source', 'shareholder')->json('data.id');

        $capital = Capital::findOrFail($id);
        $this->assertSame([$holder->id, $account->id, 'shareholder_portal', null], [$capital->share_holder_id, $capital->recorded_by, $capital->source, $capital->journal_entry_id]);
        $this->assertSame(0, JournalEntry::count(), 'no journal while pending');
        $this->assertSame(0.0, $ledger->balance($companyId, Account::Capital));
        $this->getJson('/api/v1/portal/shareholder/dashboard')->assertJsonPath('data.my_capital', 0)->assertJsonPath('data.pending_contributions', 1);
        $this->assertTrue(AuditLog::where('action', 'Capital.submitted_by_shareholder')->where('auditable_id', $id)->exists());
        $this->getJson("/api/v1/portal/shareholder/capital/{$id}/receipt")->assertOk();

        // The shareholder cannot approve (no staff access at all).
        $this->postJson("/api/v1/capital/capitals/{$id}/approve")->assertForbidden();

        // Staff see the source; a different authorised user approves exactly once.
        $this->actAs($this->admin);
        $row = collect($this->getJson('/api/v1/capital/capitals')->assertOk()->json('data.share_holders'))->firstWhere('id', $holder->id)['capitals'][0];
        $this->assertSame(['shareholder_portal', 'Submitted by shareholder', 'DEVFLOW-TRX-1', true], [$row['source'], $row['source_label'], $row['receipt_number'], $row['can_approve']]);

        $this->postJson("/api/v1/capital/capitals/{$id}/approve", [], ['Idempotency-Key' => 'approve-portal-0001'])->assertOk()->assertJsonPath('data.status', 'posted');
        $this->postJson("/api/v1/capital/capitals/{$id}/approve", [], ['Idempotency-Key' => 'approve-portal-0001'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        $this->postJson("/api/v1/capital/capitals/{$id}/approve")->assertUnprocessable();

        $this->assertSame(1, JournalEntry::count(), 'one journal');
        $lines = JournalEntry::sole()->lines()->with('account')->get();
        $this->assertEquals(1000, $lines->where('debit', '>', 0)->sole()->debit);
        $this->assertSame(Account::Bank, $lines->where('debit', '>', 0)->sole()->account->key);
        $this->assertSame(Account::Capital, $lines->where('credit', '>', 0)->sole()->account->key);
        $this->assertSame(1000.0, $ledger->balance($companyId, Account::Capital));
        $this->assertSame(1000.0, $ledger->balance($companyId, Account::Bank, bankAccount: $bank));
        $this->assertTrue(AuditLog::where('action', 'Capital.approved')->where('auditable_id', $id)->exists());

        $this->actAs($account);
        $this->getJson('/api/v1/portal/shareholder/capital')->assertJsonPath('data.rows.0.status', 'posted')->assertJsonPath('data.total_contributed', 1000);
        $this->getJson('/api/v1/portal/shareholder/dashboard')->assertJsonPath('data.my_capital', 1000)->assertJsonPath('data.pending_contributions', 0);
        $this->postJson("/api/v1/portal/shareholder/capital/{$id}/cancel")->assertUnprocessable();
    }

    public function test_cancel_own_pending_only_and_a_linked_staff_account_never_approves_its_own_capital(): void
    {
        [$holder, $account] = $this->portalHolder('ALPHA', '0768999211');
        $this->actAs($account);
        $id = $this->postJson('/api/v1/portal/shareholder/capital', ['amount' => 2500, 'payment_method' => 'CASH', 'transaction_reference' => 'DEVFLOW-CASH'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/portal/shareholder/capital/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->postJson("/api/v1/portal/shareholder/capital/{$id}/cancel")->assertUnprocessable();
        $this->actAs($this->admin);
        $this->postJson("/api/v1/capital/capitals/{$id}/approve")->assertUnprocessable();

        $staffCapital = Capital::create(['company_id' => $this->admin->company_id, 'share_holder_id' => $holder->id, 'amount' => 900, 'pay_method' => 'CASH', 'status' => 'pending', 'recorded_by' => $this->admin->id]);
        $this->actAs($account);
        $this->postJson("/api/v1/portal/shareholder/capital/{$staffCapital->id}/cancel")->assertForbidden();

        // A staff account linked to a shareholder: holds capital.manage, still cannot approve capital of their own holding.
        $staffHolder = $this->secondApprover($this->admin, 'admin');
        $staffHolder->permissionOverrides()->createMany([
            ['permission' => 'capital.manage', 'granted' => true],
            ['permission' => 'shareholder.portal', 'granted' => true],
            ['permission' => 'shareholder.capital.submit', 'granted' => true],
        ]);
        $linked = $this->holder('STAFFER', $staffHolder->phone);
        app(ShareholderAccounts::class)->provision($linked, $this->admin);
        $this->actAs($staffHolder);
        $ownId = $this->postJson('/api/v1/portal/shareholder/capital', ['amount' => 4000, 'payment_method' => 'CASH', 'transaction_reference' => 'DEVFLOW-OWN'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/capital/capitals/{$ownId}/approve")->assertForbidden();

        $recordedByOther = Capital::create(['company_id' => $this->admin->company_id, 'share_holder_id' => $linked->id, 'amount' => 800, 'pay_method' => 'CASH', 'status' => 'pending', 'recorded_by' => $this->admin->id]);
        $this->postJson("/api/v1/capital/capitals/{$recordedByOther->id}/approve")->assertForbidden();
        $this->postJson("/api/v1/capital/capitals/{$staffCapital->id}/approve")->assertOk();
        $this->assertSame(1, JournalEntry::count());

        // The Super Admin is exempt: linked to a shareholder, they approve the capital of their own holding.
        $superAdminHolder = $this->secondApprover($this->admin);
        app(ShareholderAccounts::class)->provision($this->holder('SUPERSTAFF', $superAdminHolder->phone), $this->admin);
        $this->actAs($superAdminHolder);
        $superAdminOwnId = $this->postJson('/api/v1/portal/shareholder/capital', ['amount' => 1500, 'payment_method' => 'CASH', 'transaction_reference' => 'DEVFLOW-SUPER'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/capital/capitals/{$superAdminOwnId}/approve")->assertOk();
        $this->assertSame(2, JournalEntry::count());
    }
}
