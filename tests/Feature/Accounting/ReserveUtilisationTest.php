<?php

declare(strict_types=1);

use App\Domain\Accounting\Actions\DecideReserveUtilisationAction;
use App\Domain\Accounting\Actions\RequestReserveUtilisationAction;
use App\Domain\Accounting\DTOs\ReserveUtilisationData;
use App\Domain\Accounting\Enums\ReserveUtilisationPurpose;
use App\Domain\Accounting\Enums\ReserveUtilisationStatus;
use App\Domain\Accounting\Exceptions\ReserveException;
use App\Domain\Accounting\Policies\AccountingPolicy;
use App\Domain\Accounting\Services\ReserveBalanceReader;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\Services\TrialBalanceBuilder;
use App\Models\User;
use App\Support\Money;
use Database\Seeders\ChartOfAccountSeeder;

/**
 * Spending the Reserve — Decision Register D1.
 *
 * "Reserve transfers require Admin approval. Branches cannot directly use
 * Reserve funds. Reserve belongs to Headquarters / Administration."
 */
beforeEach(function (): void {
    seedOrganization();
    $this->seed(ChartOfAccountSeeder::class);

    $this->finance = User::factory()->role(RoleName::Finance)->create();
    $this->admin = User::factory()->role(RoleName::Admin)->create();

    $this->accounts = app(AccountResolver::class);
});

/** Puts money in the Reserve so there is something to release. */
function fundReserve(string $amount): void
{
    $accounts = app(AccountResolver::class);

    app(LedgerService::class)->post(
        'Test reserve funding',
        JournalSourceType::ReserveAppropriation,
        null,
        [
            JournalLine::debit($accounts->systemId(SystemAccountCode::Profit), Money::of($amount)),
            JournalLine::credit($accounts->systemId(SystemAccountCode::Reserve), Money::of($amount)),
        ],
        User::query()->firstOrFail(),
    );
}

function requestReserve(User $requester, string $amount, ?ReserveUtilisationPurpose $purpose = null)
{
    return app(RequestReserveUtilisationAction::class)->handle(
        new ReserveUtilisationData(
            purpose: $purpose ?? ReserveUtilisationPurpose::ReturnToCapital,
            amount: $amount,
            narrative: 'Strengthening the capital base after a strong quarter.',
        ),
        $requester,
    );
}

it('posts nothing when a request is raised', function (): void {
    fundReserve('1000000.00');

    $before = app(ReserveBalanceReader::class)->balance();

    $request = requestReserve($this->finance, '250000.00');

    // Reserve moves on approval, not on request, so a queue of proposals never
    // touches the trial balance.
    expect($request->status)->toBe(ReserveUtilisationStatus::Pending)
        ->and($request->journal_entry_id)->toBeNull()
        ->and(app(ReserveBalanceReader::class)->balance()->toDecimalString())
        ->toBe($before->toDecimalString());
});

it('moves reserve into capital when Admin approves a return to capital', function (): void {
    fundReserve('1000000.00');

    $request = requestReserve($this->finance, '250000.00');
    $approved = app(DecideReserveUtilisationAction::class)->approve($request, $this->admin);

    expect($approved->status)->toBe(ReserveUtilisationStatus::Approved)
        ->and($approved->journal_entry_id)->not->toBeNull();

    $trial = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

    // Reserve drawn down, Capital increased by the same amount.
    expect($trial[SystemAccountCode::Reserve->value]['balance'])->toBe('750000.00')
        ->and($trial[SystemAccountCode::Capital->value]['balance'])->toBe('250000.00');
});

it('keeps the ledger balanced after a release', function (): void {
    fundReserve('1000000.00');

    app(DecideReserveUtilisationAction::class)->approve(
        requestReserve($this->finance, '250000.00'),
        $this->admin,
    );

    expect(app(TrialBalanceBuilder::class)->build()['balanced'])->toBeTrue();
});

it('refuses to release more than the fund holds', function (): void {
    fundReserve('100000.00');

    app(DecideReserveUtilisationAction::class)->approve(
        requestReserve($this->finance, '250000.00'),
        $this->admin,
    );
})->throws(ReserveException::class);

it('checks the balance at approval rather than at request', function (): void {
    fundReserve('300000.00');

    // Two proposals, each affordable alone, together exceeding the fund.
    $first = requestReserve($this->finance, '200000.00');
    $second = requestReserve($this->finance, '200000.00');

    app(DecideReserveUtilisationAction::class)->approve($first, $this->admin);

    // The arithmetic that matters is the arithmetic on the day of release.
    expect(fn () => app(DecideReserveUtilisationAction::class)->approve($second, $this->admin))
        ->toThrow(ReserveException::class);
});

it('refuses a second decision on an already decided request', function (): void {
    fundReserve('1000000.00');

    $request = requestReserve($this->finance, '100000.00');
    app(DecideReserveUtilisationAction::class)->approve($request, $this->admin);

    app(DecideReserveUtilisationAction::class)->approve($request->fresh(), $this->admin);
})->throws(ReserveException::class);

it('posts nothing when a request is rejected', function (): void {
    fundReserve('1000000.00');

    $request = requestReserve($this->finance, '250000.00');

    $rejected = app(DecideReserveUtilisationAction::class)
        ->reject($request, 'Capital is adequate this quarter.', $this->admin);

    expect($rejected->status)->toBe(ReserveUtilisationStatus::Rejected)
        ->and($rejected->journal_entry_id)->toBeNull()
        ->and(app(ReserveBalanceReader::class)->balance()->toDecimalString())->toBe('1000000.00');
});

it('does not let the requester approve their own request', function (): void {
    fundReserve('1000000.00');

    /*
     * §14's separation of duties, applied to the Reserve. Without this, D1's
     * Admin approval step would be a formality for anyone holding both grants.
     */
    $adminRequest = requestReserve($this->admin, '100000.00');

    expect(app(AccountingPolicy::class)->decideReserve($this->admin, $adminRequest))->toBeFalse();
});

it('lets Admin decide a request Finance raised', function (): void {
    fundReserve('1000000.00');

    $request = requestReserve($this->finance, '100000.00');

    expect(app(AccountingPolicy::class)->decideReserve($this->admin, $request))->toBeTrue();
});

it('does not let Finance approve reserve at all', function (): void {
    fundReserve('1000000.00');

    $request = requestReserve($this->admin, '100000.00');

    // Finance proposes; only Admin releases (D1).
    expect(app(AccountingPolicy::class)->decideReserve($this->finance, $request))->toBeFalse();
});

it('issues sequential references', function (): void {
    fundReserve('1000000.00');

    expect(requestReserve($this->finance, '1000.00')->reference)->toBe('RU-0000001')
        ->and(requestReserve($this->finance, '2000.00')->reference)->toBe('RU-0000002');
});

it('returns reserve to capital whatever the stated purpose', function (): void {
    fundReserve('1000000.00');

    $request = app(RequestReserveUtilisationAction::class)->handle(
        new ReserveUtilisationData(
            purpose: ReserveUtilisationPurpose::NewDepartment,
            amount: '150000.00',
            narrative: 'Standing up the recovery department at head office.',
        ),
        $this->finance,
    );

    app(DecideReserveUtilisationAction::class)->approve($request, $this->admin);

    $trial = collect(app(TrialBalanceBuilder::class)->build()['rows'])->keyBy('code');

    /*
     * "Inaweza kurudi kwa njia ya mtaji" — it returns by way of capital.
     *
     * The Reserve is a control account holding no cash, so a release cannot
     * move money into a bank account; it un-reserves equity, and the department
     * is then funded from capital and spends through the ordinary expense path.
     * An earlier draft credited a named bank account, which drained the very
     * account it claimed to fund — assets are debit-normal.
     */
    expect($trial[SystemAccountCode::Reserve->value]['balance'])->toBe('850000.00')
        ->and($trial[SystemAccountCode::Capital->value]['balance'])->toBe('150000.00');
});

it('records the branch a release is for without posting to it', function (): void {
    fundReserve('1000000.00');

    $branch = App\Models\Branch::query()->firstOrFail();

    $request = app(RequestReserveUtilisationAction::class)->handle(
        new ReserveUtilisationData(
            purpose: ReserveUtilisationPurpose::NewBranch,
            amount: '400000.00',
            narrative: 'Opening the Bukoba branch in the next quarter.',
            targetBranchId: (int) $branch->getKey(),
        ),
        $this->finance,
    );

    $approved = app(DecideReserveUtilisationAction::class)->approve($request, $this->admin);

    expect($approved->target_branch_id)->toBe((int) $branch->getKey());

    // Capital is company-wide: tagging the credit to a branch would read as
    // that branch holding equity of its own.
    $lines = App\Models\JournalEntryLine::query()
        ->where('journal_entry_id', $approved->journal_entry_id)
        ->get();

    expect($lines)->toHaveCount(2)
        ->and($lines->pluck('branch_id')->filter()->all())->toBe([]);
});
