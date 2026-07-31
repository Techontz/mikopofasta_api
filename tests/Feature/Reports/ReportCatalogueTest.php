<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\Services\ReportRegistry;

beforeEach(function (): void {
    seedStaffBook();
});

/**
 * The twenty-one reports §15.6 names, by slug. Listed here rather than read
 * from the registry so that deleting one is a test failure rather than a
 * silently shorter list.
 *
 * @var list<string>
 */
const SPEC_REPORTS = [
    'portfolio', 'repayment', 'arrears', 'recovery', 'cashflow', 'branch-pnl',
    'branch-efficiency', 'hq-cashflow', 'payroll', 'commission', 'zone-commission',
    'financial-statements', 'audit-trail', 'suspense', 'reversals', 'daily-collection',
    'daily-disbursement', 'branch-ranking', 'segmentation', 'age-analysis',
    'repayment-behavior',
];

/**
 * Absent from §15.6's list, and each named somewhere else.
 *
 * The first three are Phase 8's. The last four are §17 of the HR document,
 * which lists six reports the module must produce — Payroll, Staff Payslip,
 * Commission, Staff Loan, Staff Advance and Staff Fund Balance. Payroll and
 * Commission were already in §15.6's twenty-one; these are the other four.
 *
 * Listed rather than derived, for the same reason SPEC_REPORTS is: adding a
 * report should be a deliberate edit here, not something that appears.
 */
const EXTRA_REPORTS = [
    'trial-balance', 'performance',
    'staff-payslip', 'staff-loan', 'staff-advance', 'staff-fund',
    'executive-summary',
];

describe('the catalogue', function (): void {
    it('publishes every report §15.6 names', function (): void {
        $slugs = array_keys(app(ReportRegistry::class)->all());

        foreach (SPEC_REPORTS as $slug) {
            expect($slugs)->toContain($slug);
        }
    });

    it('publishes the three Phase 8 additions and nothing else beyond the spec', function (): void {
        $slugs = array_keys(app(ReportRegistry::class)->all());

        expect($slugs)->toHaveCount(count(SPEC_REPORTS) + count(EXTRA_REPORTS))
            ->and(array_values(array_diff($slugs, SPEC_REPORTS)))->toBe(EXTRA_REPORTS);
    });

    it('serves the catalogue over HTTP', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports')->assertOk();

        expect($response->json('data'))->toHaveCount(count(SPEC_REPORTS) + count(EXTRA_REPORTS))
            ->and($response->json('meta.generated_at'))->not->toBeNull()
            ->and(collect($response->json('data'))->pluck('slug'))->toContain('portfolio', 'trial-balance');
    });

    it('groups every report into one of the six the frontend defines', function (): void {
        $groups = ['Portfolio', 'Collections', 'Financial', 'Branch', 'HR', 'Compliance'];

        foreach (app(ReportRegistry::class)->all() as $report) {
            expect($groups)->toContain($report->group());
        }
    });

    it('declares only the four filters §15.6 defines', function (): void {
        foreach (app(ReportRegistry::class)->all() as $report) {
            foreach ($report->supportedFilters() as $filter) {
                expect(['branchId', 'period', 'from', 'to'])->toContain($filter);
            }
        }
    });
});

describe('every report', function (): void {
    it('computes without error and answers the contract', function (): void {
        foreach (app(ReportRegistry::class)->all() as $slug => $report) {
            $result = $report->compute(new ReportFilters);

            expect($result->columns)->not->toBeEmpty("[{$slug}] has no columns")
                // Phase 8: every figure must be traceable. A report that does
                // not say where its numbers came from is not traceable.
                ->and($result->reconciliation)->not->toBeNull("[{$slug}] has no reconciliation note")
                ->and($result->emptyMessage)->not->toBeNull("[{$slug}] has no empty message");
        }
    });

    it('returns rows keyed by its own declared columns', function (): void {
        foreach (app(ReportRegistry::class)->all() as $slug => $report) {
            $result = $report->compute(new ReportFilters);
            $keys = array_map(static fn ($c): string => $c->toArray()['key'], $result->columns);

            foreach ($result->rows as $row) {
                // A row carrying a key no column declares would never be
                // rendered — silently dropped data is worse than missing data.
                expect(array_keys($row))->toEqualCanonicalizing($keys, "[{$slug}] row keys differ from columns");
            }
        }
    });

    it('serves over HTTP with the two meta keys §15.6 mandates', function (): void {
        actingAsFinance();

        foreach (array_keys(app(ReportRegistry::class)->all()) as $slug) {
            $response = $this->getJson("/api/v1/reports/{$slug}")->assertOk();

            expect($response->json('meta.generated_at'))->not->toBeNull("[{$slug}] has no generated_at")
                ->and($response->json('meta.filters_applied'))->not->toBeNull("[{$slug}] has no filters_applied")
                ->and($response->json('meta.report.slug'))->toBe($slug);
        }
    });

    it('honours a branch filter without erroring', function (): void {
        actingAsFinance();
        $branchId = App\Models\Branch::query()->where('name', 'Kakonko')->value('id');

        foreach (app(ReportRegistry::class)->all() as $slug => $report) {
            $this->getJson("/api/v1/reports/{$slug}?branch_id={$branchId}")->assertOk();
        }
    });

    it('honours a period filter without erroring', function (): void {
        actingAsFinance();

        foreach (array_keys(app(ReportRegistry::class)->all()) as $slug) {
            $this->getJson("/api/v1/reports/{$slug}?period=".currentPeriod())->assertOk();
        }
    });

    it('honours a date window without erroring', function (): void {
        actingAsFinance();
        $from = now()->subYear()->toDateString();
        $to = now()->toDateString();

        foreach (array_keys(app(ReportRegistry::class)->all()) as $slug) {
            $this->getJson("/api/v1/reports/{$slug}?from={$from}&to={$to}")->assertOk();
        }
    });
});

describe('filters', function (): void {
    it('echoes back only the filters the report honours', function (): void {
        actingAsFinance();

        // Zone Commission declares `period` alone. Sending a branch as well
        // must not have it echoed, or a reader would believe the figures were
        // branch-scoped when they were not.
        $branchId = App\Models\Branch::query()->value('id');

        $response = $this->getJson('/api/v1/reports/zone-commission?period='.currentPeriod()."&branch_id={$branchId}")
            ->assertOk();

        expect($response->json('meta.filters_applied'))->toBe(['period' => currentPeriod()]);
    });

    it('echoes an empty object when nothing was filtered', function (): void {
        actingAsFinance();

        $response = $this->getJson('/api/v1/reports/portfolio')->assertOk();

        expect($response->json('meta.filters_applied'))->toBe([]);
    });

    it('rejects a malformed period, date or unknown branch', function (): void {
        actingAsFinance();

        $this->getJson('/api/v1/reports/portfolio?period=July-2026')
            ->assertStatus(422)->assertJsonValidationErrors('period');

        $this->getJson('/api/v1/reports/cashflow?from=01-07-2026')
            ->assertStatus(422)->assertJsonValidationErrors('from');

        // A window that runs backwards would silently return nothing.
        $this->getJson('/api/v1/reports/cashflow?from=2026-07-31&to=2026-07-01')
            ->assertStatus(422)->assertJsonValidationErrors('to');

        $this->getJson('/api/v1/reports/portfolio?branch_id=99999')
            ->assertStatus(422)->assertJsonValidationErrors('branch_id');
    });

    it('rejects a period of month 13', function (): void {
        actingAsFinance();

        // Carbon would happily read 2026-13 as January 2027 and report the
        // wrong month's figures.
        $this->getJson('/api/v1/reports/payroll?period=2026-13')
            ->assertStatus(422)->assertJsonValidationErrors('period');
    });
});

describe('authorization and branch scope', function (): void {
    it('requires reports.view', function (): void {
        /*
         * Every §14 role holds reports.view, so the grant is stripped from the
         * role for this test. Revoking it from the USER would prove nothing:
         * the permission is inherited from the role, and a direct revoke does
         * not override an inherited grant.
         */
        $user = App\Models\User::factory()->role(RoleName::LoanOfficer)->create();
        $user->role->revokePermissionTo('reports.view');
        app(Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        forgetAuthGuards();
        Laravel\Sanctum\Sanctum::actingAs($user->fresh(['role']), ['*']);

        $this->getJson('/api/v1/reports')->assertForbidden();
        $this->getJson('/api/v1/reports/portfolio')->assertForbidden();
    });

    it('requires authentication', function (): void {
        forgetAuthGuards();

        $this->getJson('/api/v1/reports/portfolio')->assertUnauthorized();
    });

    it('pins a branch-scoped user to their own branch whatever they ask for', function (): void {
        $kakonko = App\Models\Branch::query()->where('name', 'Kakonko')->value('id');
        $missenyi = App\Models\Branch::query()->where('name', 'Missenyi')->value('id');

        officerAt('Kakonko', RoleName::LoanOfficer);

        // §13: a report must not be a way around the scoping every other
        // endpoint enforces.
        $response = $this->getJson("/api/v1/reports/portfolio?branch_id={$missenyi}")->assertOk();

        expect($response->json('meta.filters_applied.branch_id'))->toBe((string) $kakonko);
    });

    it('leaves a company-wide report unscoped', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        // The trial balance for one branch is not "the trial balance", and
        // §14 gives every role reports.view precisely because the reports are
        // read-only.
        $response = $this->getJson('/api/v1/reports/audit-trail')->assertOk();

        expect($response->json('meta.filters_applied'))->toBe([]);
    });

    it('lets a user holding branches.view_all choose any branch', function (): void {
        $missenyi = App\Models\Branch::query()->where('name', 'Missenyi')->value('id');

        actingAsFinance();

        $response = $this->getJson("/api/v1/reports/portfolio?branch_id={$missenyi}")->assertOk();

        expect($response->json('meta.filters_applied.branch_id'))->toBe((string) $missenyi);
    });
});

describe('unknown reports', function (): void {
    it('returns the standard not-found envelope', function (): void {
        actingAsFinance();

        $this->getJson('/api/v1/reports/does-not-exist')
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    });

    it('has no endpoint that writes a report', function (): void {
        actingAsFinance();

        // Reports maintain no data store of their own — there is nothing to
        // write to.
        $this->postJson('/api/v1/reports/portfolio', [])->assertStatus(405);
    });
});
