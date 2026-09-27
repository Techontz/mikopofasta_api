<?php

namespace Tests\Feature\Api\Accounting;

use App\Enums\Account;
use App\Models\Branch;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsApiTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;

    public function test_chart_lists_accounts_by_type_with_normal_balance_aware_balances(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $ledger->openingBalance($admin->company_id, Account::Principal, 500000, branch: $admin->branch_id, date: CarbonImmutable::parse('2026-08-01'));
        $this->postIncome($admin->company_id, $admin->branch_id, 10000, 1000, 2000, 500, '2026-08-10');

        $response = $this->getJson('/api/v1/accounting/accounts')->assertOk();

        $types = collect($response->json('data'))->keyBy('type');
        $this->assertSame(['asset', 'liability', 'equity', 'income', 'expense'], $types->keys()->all());

        $equity = collect($types['equity']['accounts'])->keyBy('key');
        $this->assertEquals(500000, $equity['capital']['balance']);
        $this->assertSame('credit', $equity['capital']['normal_balance']);

        $income = collect($types['income']['accounts'])->keyBy('key');
        $this->assertEquals(10000, $income['interest_income']['balance']);
        $this->assertEquals(12500, $types['income']['balance']);

        $assets = collect($types['asset']['accounts'])->keyBy('key');
        $this->assertEquals(500000, $assets['principal']['balance']);
        $this->assertSame('debit', $assets['principal']['normal_balance']);
        $this->assertSame($admin->branch->name, $assets['principal']['children'][0]['scope']);
        $this->assertArrayHasKey('offset', $assets->all());
        $this->assertArrayHasKey('loan_arrears', $assets->all());
    }

    public function test_as_of_date_and_branch_filters(): void
    {
        $admin = $this->signInAdmin();
        $other = Branch::factory()->create(['company_id' => $admin->company_id]);
        $this->postIncome($admin->company_id, $admin->branch_id, 10000, 0, 0, 0, '2026-08-10');
        $this->postIncome($admin->company_id, $other->id, 4000, 0, 0, 0, '2026-08-20');

        $interest = fn (array $query) => collect(collect($this->getJson('/api/v1/accounting/accounts?'.http_build_query($query))->assertOk()->json('data'))
            ->firstWhere('type', 'income')['accounts'])->firstWhere('key', 'interest_income')['balance'];

        $this->assertEquals(14000, $interest([]));
        $this->assertEquals(10000, $interest(['as_of' => '2026-08-15']));
        $this->assertEquals(4000, $interest(['branch_id' => $other->id]));
        $this->assertEquals(0, $interest(['branch_id' => 'hq']));
    }

    public function test_capital_is_hidden_without_capital_view_but_principal_is_visible_to_finance(): void
    {
        $admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($admin->company_id, Account::Principal, 300000, branch: $admin->branch_id);
        $finance = $this->employeeWithRole($admin, 'finance');

        $response = $this->actingAs($finance)->getJson('/api/v1/accounting/accounts')->assertOk();
        $keys = collect($response->json('data'))->flatMap(fn (array $type) => collect($type['accounts'])->pluck('key'));

        $this->assertNotContains('capital', $keys);
        $this->assertContains('principal', $keys);
        $this->assertNotContains('capital', collect($this->getJson('/api/v1/accounting/account-options')->json('data'))->pluck('value'));
    }

    public function test_roles_without_accounting_view_are_forbidden_and_branch_roles_see_only_their_branch(): void
    {
        $admin = $this->signInAdmin();
        $other = Branch::factory()->create(['company_id' => $admin->company_id]);
        $this->postIncome($admin->company_id, $other->id, 4000, 0, 0, 0, '2026-08-20');

        $officer = $this->employeeWithRole($admin, 'loan_officer');
        $this->actingAs($officer)->getJson('/api/v1/accounting/accounts')->assertForbidden();

        $manager = $this->employeeWithRole($admin, 'branch_manager', ['accounting.view']);
        $this->actingAs($manager)->getJson('/api/v1/accounting/accounts?branch_id='.$other->id)->assertForbidden();
        $income = collect(collect($this->actingAs($manager)->getJson('/api/v1/accounting/accounts')->assertOk()->json('data'))->firstWhere('type', 'income')['accounts']);
        $this->assertEquals(0, $income->firstWhere('key', 'interest_income')['balance']);
    }
}
