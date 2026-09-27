<?php

namespace Tests\Feature\Api\Accounting;

use App\Enums\Account;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LedgerPeriodLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_refuses_entries_dated_inside_a_closed_period_only(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        AccountingPeriod::create(['company_id' => $admin->company_id, 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'closed']);
        AccountingPeriod::create(['company_id' => $admin->company_id, 'period_start' => '2026-07-01', 'period_end' => '2026-07-31', 'status' => 'open']);

        $ledger->openingBalance($admin->company_id, Account::Company, 100, date: CarbonImmutable::parse('2026-07-31'));
        $ledger->openingBalance($admin->company_id, Account::Company, 100, date: CarbonImmutable::parse('2026-09-01'));
        $ledger->openingBalance(Company::factory()->create()->id, Account::Company, 100, date: CarbonImmutable::parse('2026-08-15'));

        foreach (['2026-08-01', '2026-08-15', '2026-08-31'] as $date) {
            try {
                $ledger->openingBalance($admin->company_id, Account::Company, 100, date: CarbonImmutable::parse($date));
                $this->fail("Entry dated {$date} must be refused.");
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('entry_date', $exception->errors());
            }
        }

        $this->assertSame(200.0, $ledger->balance($admin->company_id, Account::Company));
    }

    public function test_reversal_is_refused_when_today_falls_in_a_closed_period(): void
    {
        $admin = $this->signInAdmin();
        $ledger = app(Ledger::class);
        $entry = $ledger->openingBalance($admin->company_id, Account::Company, 100, date: CarbonImmutable::parse('2026-07-10'));
        AccountingPeriod::create(['company_id' => $admin->company_id, 'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'status' => 'closed']);

        $this->expectException(ValidationException::class);
        $ledger->reverse($entry, 'Late correction');
    }
}
