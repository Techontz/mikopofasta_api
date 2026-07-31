<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Hr\Enums\EmploymentStatus;
use App\Domain\Hr\Services\BranchProfitCalculator;
use App\Domain\Hr\Services\CommissionCalculator;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CommissionPool;
use App\Models\JournalEntry;
use App\Models\PayrollLine;
use App\Models\StaffProfile;
use App\Models\ZoneCommissionDistribution;
use App\Support\Money;

beforeEach(function (): void {
    seedStaffBook();
});

/** Generates the period's pools as Finance, and returns the response. */
function generatePools(?string $period = null)
{
    actingAsFinance();

    return test()->postJson('/api/v1/commission/generate', ['period' => $period ?? currentPeriod()]);
}

describe('pool generation', function (): void {
    it('creates one pool per branch, read off the ledger', function (): void {
        $response = generatePools()->assertCreated();

        expect($response->json('data'))->toHaveCount(Branch::query()->count());

        // The profit is not a figure somebody typed in — it is the branch's
        // income minus expense for the period (§8).
        $kakonko = Branch::query()->where('name', 'Kakonko')->sole();
        $pool = CommissionPool::query()->where('branch_id', $kakonko->getKey())->sole();

        expect($pool->branch_profit)
            ->toBe(app(BranchProfitCalculator::class)->forPeriod($kakonko, currentPeriod())->toDecimalString());
    });

    it('applies the HQ hold and the 20% pool rate', function (): void {
        generatePools()->assertCreated();

        foreach (CommissionPool::query()->get() as $pool) {
            $expected = app(CommissionCalculator::class)
                ->computePool($pool->branchProfit(), $pool->lossCarryForward());

            expect($pool->hq_hold_amount)->toBe($expected->hqHoldAmount->toDecimalString())
                ->and($pool->distributable_profit)->toBe($expected->distributableProfit->toDecimalString())
                ->and($pool->pool_amount)->toBe($expected->poolAmount->toDecimalString())
                ->and($pool->pool_percentage)->toBe('20.000');
        }
    });

    it('distributes a profitable pool weighted by base salary', function (): void {
        generatePools()->assertCreated();

        $pool = CommissionPool::query()->with('distributions.staffProfile')
            ->get()
            ->first(fn (CommissionPool $p): bool => $p->isDistributable());

        expect($pool)->not->toBeNull()
            ->and($pool->distributions)->not->toBeEmpty();

        $expected = app(CommissionCalculator::class)->distributePool(
            $pool->poolAmount(),
            StaffProfile::query()
                ->where('branch_id', $pool->branch_id)
                ->where('commission_eligible', true)
                ->where('employment_status', EmploymentStatus::Active)
                ->orderBy('id')->get(),
        );

        $actual = $pool->distributions->sortBy('staff_profile_id')
            ->map(fn ($d): string => $d->share_amount)->values()->all();

        $wanted = collect($expected)->sortBy('staffProfileId')
            ->map(fn (array $s): string => $s['shareAmount']->toDecimalString())->values()->all();

        expect($actual)->toBe($wanted);
    });

    it('gives a loss-making branch a zero pool and no distributions', function (): void {
        generatePools()->assertCreated();

        // A branch with no lending activity has no income, so its
        // distributable profit is not positive — §11's rule applies to it just
        // as it would to a branch working off a real loss.
        $blocked = CommissionPool::query()->with('distributions')
            ->get()
            ->reject(fn (CommissionPool $p): bool => $p->isDistributable());

        expect($blocked)->not->toBeEmpty();

        foreach ($blocked as $pool) {
            expect($pool->pool_amount)->toBe('0.00')
                ->and($pool->distributions)->toBeEmpty();
        }
    });

    it('reports how many branches a loss blocked', function (): void {
        $response = generatePools()->assertCreated();

        $blocked = CommissionPool::query()->get()
            ->reject(fn (CommissionPool $p): bool => $p->isDistributable())->count();

        expect($response->json('meta.blockedByLoss'))->toBe($blocked);
    });

    it('carries an unrecovered loss into the next period', function (): void {
        $branch = Branch::query()->where('name', 'Lindi')->sole();
        $period = currentPeriod();
        $next = now()->addMonth()->format('Y-m');

        // Force this period into a real loss.
        CommissionPool::query()->updateOrCreate(
            ['branch_id' => $branch->getKey(), 'period' => $period],
            [
                'branch_profit' => '-300000.00',
                'loss_carry_forward' => '0.00',
                'hq_hold_amount' => '-6000.00',
                'distributable_profit' => '-294000.00',
                'pool_percentage' => '20.000',
                'pool_amount' => '0.00',
            ],
        );

        generatePools($next)->assertCreated();

        $carried = CommissionPool::query()
            ->where('branch_id', $branch->getKey())->where('period', $next)->sole();

        // OSC-5's reading: the shortfall follows the branch forward.
        expect($carried->loss_carry_forward)->toBe('294000.00');
    });

    it('carries nothing forward from a profitable period', function (): void {
        generatePools()->assertCreated();
        generatePools(now()->addMonth()->format('Y-m'))->assertCreated();

        $kakonko = Branch::query()->where('name', 'Kakonko')->sole();

        $next = CommissionPool::query()
            ->where('branch_id', $kakonko->getKey())
            ->where('period', now()->addMonth()->format('Y-m'))
            ->sole();

        expect($next->loss_carry_forward)->toBe('0.00');
    });

    it('replaces rather than duplicates when re-run', function (): void {
        generatePools()->assertCreated();
        $first = CommissionPool::query()->count();
        $shares = App\Models\CommissionDistribution::query()->count();

        generatePools()->assertCreated();

        expect(CommissionPool::query()->count())->toBe($first)
            ->and(App\Models\CommissionDistribution::query()->count())->toBe($shares);
    });

    it('posts nothing to the ledger', function (): void {
        $before = JournalEntry::query()->count();

        $response = generatePools()->assertCreated();

        /*
         * A pool is an entitlement, not a transaction. The money is
         * recognised once, as Commission Expense on the recipient's payroll
         * entry (§5); posting it here as well would expense the same
         * shillings twice.
         */
        expect(JournalEntry::query()->count())->toBe($before)
            ->and($response->json('meta.ledgerPosting'))->toContain('none');
    });
});

describe('zone override', function (): void {
    it('is 5% of the combined pools of the zone', function (): void {
        generatePools()->assertCreated();

        $override = ZoneCommissionDistribution::query()->with('zone.branches')->first();

        expect($override)->not->toBeNull();

        $base = Money::sum(
            CommissionPool::query()
                ->whereIn('branch_id', $override->zone->branches->pluck('id'))
                ->where('period', currentPeriod())
                ->get()
                ->map(fn (CommissionPool $p): Money => $p->poolAmount()),
        );

        expect($override->total_pool_base)->toBe($base->toDecimalString())
            ->and($override->override_percentage)->toBe('5.000')
            ->and($override->override_amount)
            ->toBe(app(CommissionCalculator::class)->zoneOverride($base)->toDecimalString());
    });

    it('reaches the zone manager as part of their payroll commission', function (): void {
        $run = finalizedPayrollRun();

        $override = ZoneCommissionDistribution::query()->firstOrFail();
        $manager = staffFor('0754000008');

        $line = $run->lines->firstWhere('staff_profile_id', $manager->getKey());

        $branchShare = Money::sum(
            $manager->commissionShares()->get()->map(fn ($d): Money => $d->shareAmount()),
        );

        // Branch share plus the override, on one line.
        expect($line->commission_amount)
            ->toBe($branchShare->add($override->overrideAmount())->toDecimalString());
    });

    it('points at the payroll entry that expensed it, not a second one', function (): void {
        $before = JournalEntry::query()->count();
        finalizedPayrollRun();

        $override = ZoneCommissionDistribution::query()->firstOrFail();
        $manager = staffFor('0754000008');
        $line = PayrollLine::query()->where('staff_profile_id', $manager->getKey())->sole();

        // §11's override is folded into the manager's recognition entry, so it
        // is recognised exactly once and still traceable.
        expect($override->journal_entry_id)->toBe($line->journal_entry_id)
            ->and($override->journal_entry_id)->not->toBeNull();

        $commissionEntries = JournalEntry::query()->where('source_type', 'commission')->count();

        expect($commissionEntries)->toBe(0);
    });
});

describe('commission reaches the books once', function (): void {
    it('expenses exactly the total distributed', function (): void {
        $run = finalizedPayrollRun();
        $accounts = app(AccountResolver::class);

        $expensed = Money::sum(
            App\Models\JournalEntryLine::query()
                ->where('account_id', $accounts->systemId(SystemAccountCode::CommissionExpense))
                ->get()
                ->map(fn ($l): Money => $l->debitAmount()),
        );

        $awarded = Money::sum($run->lines->map(fn (PayrollLine $l): Money => $l->commissionAmount()));

        expect($expensed->toDecimalString())->toBe($awarded->toDecimalString())
            ->and($expensed->isPositive())->toBeTrue();
    });
});

describe('endpoints and RBAC', function (): void {
    it('serves a branch breakdown', function (): void {
        generatePools()->assertCreated();

        /*
         * Whichever branch actually earned a distributable pool, rather than a
         * branch named up front. Only a branch in profit distributes anything,
         * and which of them that is depends on how the seeded loan book falls
         * across branches — naming one here made this test fail the moment the
         * branch list changed, which is a fact about the fixture, not about the
         * endpoint under test.
         */
        $pool = CommissionPool::query()
            ->with('branch')
            ->where('period', currentPeriod())
            ->get()
            ->first(fn (CommissionPool $p): bool => $p->isDistributable());

        expect($pool)->not->toBeNull('the seeded book left no branch in profit');

        $response = $this->getJson("/api/v1/commission/branches/{$pool->branch_id}?period=".currentPeriod())
            ->assertOk()
            ->assertJsonPath('meta.branchName', $pool->branch->name);

        expect($response->json('data.0.distributions'))->not->toBeEmpty();
    });

    it('lets HR read commission but never generate it', function (): void {
        actingAsHr();

        $this->getJson('/api/v1/commission')->assertOk();

        // A pool comes from branch profit, which is an accounting figure —
        // §11 sequences it after month-end close, which is Finance's.
        $this->postJson('/api/v1/commission/generate', ['period' => currentPeriod()])->assertForbidden();
    });

    it('denies commission to a branch role', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/commission')->assertForbidden();
    });

    it('records generation in the audit trail', function (): void {
        generatePools()->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::CommissionPoolsGenerated->value)
            ->latest('id')->firstOrFail();

        expect($log->after_json['period'])->toBe(currentPeriod())
            ->and($log->after_json['ledger_posting'])->toContain('none');
    });
});
