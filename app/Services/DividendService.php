<?php

namespace App\Services;

use App\Enums\Account;
use App\Enums\TransactionType;
use App\Models\AccountingPeriod;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Company;
use App\Models\DividendAllocation;
use App\Models\DividendDeclaration;
use App\Models\DividendDeclarationRequest;
use App\Models\DividendPayment;
use App\Models\DividendPaymentBatch;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Dividends\DividendMath;
use App\Services\Hrm\CommissionEngine;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Monthly profit distribution (Documents: ACCOUNT OVERVIEW "16. Dividend Account" and "F. DIVIDEND PROCESS";
 * handwritten note "SHARE HOLDER & CAPITAL").
 *
 *  - PROFIT AVAILABLE is computed, never typed (user decision D3, spec §13–14):
 *      • period closed by the month-end close → Σ branch distributable profit of that month − the commission ALREADY
 *        CALCULATED for it (stored allocations only; expected or uncalculated commission never reduces it), capped by
 *        the undistributed PROFIT ACCOUNT balance (profit already distributed or absorbed by losses cannot be distributed
 *        again). C1: a declaration requires the month's commission to be CALCULATED first (422 otherwise);
 *      • period not closed → the undistributed PROFIT ACCOUNT balance is shown for information only: declarations require a
 *        CLOSED accounting period (rule 1, {@see CommissionEngine::closedPeriod()}).
 *  - Nothing here reads or moves the branch RESERVE A/C or INTEREST RESERVE (rule 3): the reinvestment is funded only from the
 *    branch INTEREST, LOAN FEE and PENALTY A/C, and dividends are paid from the company account or a bank account.
 *  - SPLIT from Settings → Dividend Settings (company percentages, default 30 shareholders / 70 principal
 *    reinvestment, always totalling 100): pool = profit × shareholder %, reinvestment = profit − pool.
 *  - MAKER/CHECKER (C1, rule 6): {@see declare()} only REQUESTS the declaration (pending, nothing posted); a different authorised
 *    user approves it ({@see approveDeclaration()}: every rule is re-validated and the figures must still match the request) or
 *    rejects it ({@see rejectDeclaration()}). A pending request blocks a second request and commission recalculation.
 *  - DECLARATION (one per company per month, posted on approval): Dr PROFIT ACCOUNT per branch / Cr DIVIDEND ACCOUNT (pool)
 *    / Cr REINVESTED PROFIT (reinvestment), plus the reinvestment fund movement into branch PRINCIPAL. Declarations made before
 *    this rule (allocation_rule NULL: June and August 2026) credited CAPITAL and stay as posted. Entitlements are split by share-register ownership on the declaration date (shares
 *    held ÷ total issued shares, {@see ShareholderOwnership}) and snapshotted; later share movements never change them.
 *  - PAYMENT (full or partial, CASH or BANK): Dr DIVIDEND ACCOUNT / Cr COMPANY ACCOUNT or the bank account. The
 *    remaining balance is re-read under a row lock on the allocation, so concurrent payments cannot overpay.
 *  - REVERSAL of a payment: opposite journal entry via {@see Ledger::reverse()}; the entitlement balance is restored.
 */
class DividendService
{
    public const PROFIT_SOURCE_PERIOD_CLOSE = 'period_close';

    public const PROFIT_SOURCE_PROFIT_ACCOUNT = 'profit_account';

    public const RULE_PROFIT_ALLOCATION = 'profit_allocation';

    public function __construct(
        private readonly Ledger $ledger,
        private readonly ShareholderOwnership $ownership,
        private readonly CommissionEngine $commission,
    ) {}

    /**
     * Company dividend split percentages ("30.00" / "70.00").
     *
     * @return array{dividend_percent: string, reinvest_percent: string}
     */
    public function settings(int $companyId): array
    {
        $company = Company::query()->findOrFail($companyId);

        return [
            'dividend_percent' => number_format((float) $company->dividend_shareholder_percent, 2, '.', ''),
            'reinvest_percent' => number_format((float) $company->dividend_reinvest_percent, 2, '.', ''),
        ];
    }

    /**
     * Undistributed profit: balance of the Profit account across all branches.
     */
    public function profitAccountBalance(int $companyId): float
    {
        return $this->ledger->balance($companyId, Account::RetainedProfit, allBranches: true) + 0.0;
    }

    /**
     * Declared but not yet paid dividends (Dividend account balance).
     */
    public function dividendBalance(int $companyId): float
    {
        return $this->ledger->balance($companyId, Account::DividendPayable, allBranches: true) + 0.0;
    }

    /**
     * Shareholder ownership from the share register on a date (today when null).
     *
     * @return Collection<int, array{share_holder: ShareHolder, capital: float, shares: int, total_shares: int, percent: float}>
     */
    public function shares(int $companyId, ?CarbonInterface $asOf = null): Collection
    {
        return $this->ownership->summary($companyId, $asOf ?? CarbonImmutable::today())
            ->map(fn (array $row): array => [
                'share_holder' => $row['share_holder'],
                'capital' => $row['total_contributed'],
                'shares' => $row['shares'],
                'total_shares' => $row['total_shares'],
                'percent' => $row['ownership_percent'],
            ]);
    }

    /**
     * Distributable profit recorded by the month-end close for a CLOSED period; null when the period is not closed.
     */
    public function closedPeriodProfit(int $companyId, CarbonImmutable $period): ?float
    {
        $accountingPeriod = $this->commission->closedPeriod($companyId, $period);

        return $accountingPeriod === null ? null : round((float) $accountingPeriod->results()->sum('distributable_profit'), 2);
    }

    /**
     * Profit Available for a period (see class docs). For a closed month: Σ branch distributable profit − CALCULATED commission
     * of the month (stored allocations; 0 when not calculated, and then nothing can be declared — C1), capped by the
     * undistributed Profit Account balance.
     *
     * @return array{period: string, period_label: string, profit_available: float, source: string, period_closed: bool, period_profit: ?float, profit_account_balance: float, note: string, distributable_profit: ?float, commission_amount: ?float, commission_calculated: bool, base_amount: ?float}
     */
    public function availableProfit(int $companyId, CarbonImmutable $period): array
    {
        $period = $period->startOfMonth();
        $balance = $this->profitAccountBalance($companyId);
        $accountingPeriod = $this->commission->closedPeriod($companyId, $period);
        $label = $period->format('F Y');
        $commission = null;
        $base = null;
        $closed = null;

        if ($accountingPeriod !== null) {
            // Specification §15: the offset is excluded from the COMMISSION base and added back before dividend and
            // reinvestment. Commission is already calculated on (distributable − offset), so distributable − commission
            // is that add-back: §59's 10,000,000 distributable with 3,000,000 offset gives a commission base of
            // 7,000,000, commission 700,000, and a distribution base of 6,300,000 + 3,000,000 = 9,300,000.
            $closed = round((float) $accountingPeriod->results()->sum('distributable_profit'), 2);
            $commission = $this->commission->commissionByBranch($accountingPeriod);
            $base = round(max(0.0, $closed - $commission['total']), 2);
            $available = max(0.0, min($base, round($balance, 2)));
            $note = "Distributable profit from the {$label} month-end close less the commission already calculated"
                .($commission['calculated'] ? '' : ' (none — commission not calculated)')
                .($available < $base ? ', limited to the undistributed Profit Account balance.' : '.');
        } else {
            $available = max(0.0, $balance);
            $note = "The month-end close has not run for {$label}; Profit Available is the undistributed Profit Account balance. Dividends can only be declared for a closed month.";
        }

        return [
            'period' => $period->format('Y-m'),
            'period_label' => $label,
            'profit_available' => round($available, 2),
            'source' => $closed !== null ? self::PROFIT_SOURCE_PERIOD_CLOSE : self::PROFIT_SOURCE_PROFIT_ACCOUNT,
            'period_closed' => $closed !== null,
            'period_profit' => $closed,
            'profit_account_balance' => round($balance, 2),
            'note' => $note,
            'distributable_profit' => $closed,
            'commission_amount' => $commission['total'] ?? null,
            'commission_calculated' => (bool) ($commission['calculated'] ?? false),
            'base_amount' => $base,
        ];
    }

    /**
     * C1 blocking reason: the month's commission must be calculated before its dividend can be declared.
     */
    public function commissionNotCalculatedMessage(CarbonImmutable $period): string
    {
        return 'Commission for '.$period->format('F Y').' must be calculated before a dividend can be declared.';
    }

    public function pendingRequestMessage(CarbonImmutable $period): string
    {
        return 'A dividend declaration for '.$period->format('F Y').' is already awaiting approval.';
    }

    /**
     * Everything a declaration for the period would record, computed exactly like {@see declare()}.
     *
     * @return array<string, mixed>
     */
    public function preview(int $companyId, CarbonImmutable $period): array
    {
        $period = $period->startOfMonth();
        $profit = $this->availableProfit($companyId, $period);
        $settings = $this->settings($companyId);
        $asOf = CarbonImmutable::today();
        $computed = $this->compute($companyId, $profit['profit_available'], $settings['dividend_percent'], $asOf);
        $existing = DividendDeclaration::where('company_id', $companyId)->whereDate('period', $period->toDateString())->value('id');
        $pending = DividendDeclarationRequest::pendingFor($companyId, $period)->with('requester')->first();
        $accountingPeriod = $this->commission->closedPeriod($companyId, $period);
        $branches = $accountingPeriod === null ? [] : $this->branchSplit($accountingPeriod, $computed['profit'], $computed['reinvest']);
        $shortfall = $this->shortfallMessage($branches);

        $blocking = match (true) {
            $existing !== null => $this->alreadyDeclaredMessage($period),
            $pending !== null => $this->pendingRequestMessage($period),
            $accountingPeriod === null => $this->notClosedMessage($period),
            ! $profit['commission_calculated'] => $this->commissionNotCalculatedMessage($period),
            $profit['profit_available'] <= 0 => "There is no profit available to distribute for {$profit['period_label']}.",
            $computed['rows'] === [] => 'No shareholder holds shares in the share register to receive a dividend.',
            $shortfall !== null => $shortfall,
            default => null,
        };

        return [
            'period' => $profit['period'],
            'period_label' => $profit['period_label'],
            'profit_available' => $profit['profit_available'],
            'profit_source' => $profit['source'],
            'period_closed' => $profit['period_closed'],
            'period_profit' => $profit['period_profit'],
            'profit_account_balance' => $profit['profit_account_balance'],
            'profit_note' => $profit['note'],
            'distributable_profit' => $profit['distributable_profit'],
            'commission_amount' => $profit['commission_amount'],
            'commission_calculated' => $profit['commission_calculated'],
            'base_amount' => $profit['period_closed'] ? DividendMath::centsToFloat($computed['profit']) : null,
            'dividend_percent' => (float) $settings['dividend_percent'],
            'reinvest_percent' => (float) $settings['reinvest_percent'],
            'dividend_pool' => DividendMath::centsToFloat($computed['pool']),
            'reinvestment_amount' => DividendMath::centsToFloat($computed['reinvest']),
            'branches' => $branches,
            'total_shares' => $computed['total_shares'],
            'as_of_date' => $asOf->toDateString(),
            'declaration_id' => $existing === null ? null : (int) $existing,
            'already_declared' => $existing !== null,
            'pending_request_id' => $pending?->id,
            'pending_requested_by' => $pending?->requester?->full_name,
            'can_declare' => $blocking === null,
            'blocking_reason' => $blocking,
            'rows' => array_map(fn (array $row): array => [
                'share_holder_id' => $row['share_holder']->id,
                'name' => $row['share_holder']->full_name,
                'shares' => $row['shares'],
                'total_shares' => $row['total_shares'],
                'ownership_percent' => $row['percent'],
                'entitlement' => DividendMath::centsToFloat($row['entitlement']),
                'contribution_total' => $row['capital'],
            ], $computed['rows']),
        ];
    }

    /**
     * REQUEST the dividend declaration of a CLOSED month whose commission is CALCULATED (C1). Every rule of {@see declarable()}
     * is checked with the company row locked; the request stores the figures the initiator saw and posts NOTHING. Only one
     * request per company and month may be pending (DB unique `pending_key`), and none once the month is declared.
     *
     * @throws ValidationException
     */
    public function declare(int $companyId, CarbonImmutable $period, Employee $employee): DividendDeclarationRequest
    {
        $period = $period->startOfMonth();

        try {
            return DB::transaction(function () use ($companyId, $period, $employee): DividendDeclarationRequest {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                $declarable = $this->declarable($companyId, $period, null);

                $request = DividendDeclarationRequest::create([
                    'company_id' => $companyId,
                    'period' => $period->toDateString(),
                    'status' => DividendDeclarationRequest::STATUS_PENDING,
                    'pending_key' => DividendDeclarationRequest::pendingKey($companyId, $period),
                    'profit_amount' => DividendMath::fromCents($declarable['computed']['profit']),
                    'distributable_profit' => $declarable['profit']['distributable_profit'],
                    'commission_amount' => $declarable['profit']['commission_amount'],
                    'dividend_percent' => $declarable['settings']['dividend_percent'],
                    'dividend_amount' => DividendMath::fromCents($declarable['computed']['pool']),
                    'reinvest_percent' => $declarable['settings']['reinvest_percent'],
                    'reinvest_amount' => DividendMath::fromCents($declarable['computed']['reinvest']),
                    'requested_by' => $employee->id,
                    'requested_at' => now(),
                ]);

                $this->audit($request, $employee, 'DividendDeclarationRequest.requested', null, DividendDeclarationRequest::STATUS_PENDING);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['period' => $this->pendingRequestMessage($period)]);
        }
    }

    /**
     * APPROVE a pending declaration request (rule 6: not by its initiator unless `approvals.self_approve` is explicitly granted).
     * Inside one transaction with the company, request and period rows locked, every rule is re-validated (period closed,
     * commission calculated, profit cap, shareholders, branch pools) and the recomputed profit, pool and reinvestment must equal
     * the request — otherwise 422 (reject it and request again). Then, dated the approval date (Fund Flow Specification §13–16):
     *
     *  1. declaration + shareholder allocations (`declared_by` = the initiator);
     *  2. DIVIDEND DECLARATION journal: Dr PROFIT ACCOUNT (per branch, by its share of the base) / Cr DIVIDEND ACCOUNT (pool)
     *     / Cr REINVESTED PROFIT (reinvestment) — never Capital (Rule 13);
     *  3. PROFIT REINVESTMENT journal (fund movement, spec §15): per branch Dr PRINCIPAL A/C / Cr INTEREST A/C, then LOAN FEE A/C,
     *     then PENALTY A/C. A branch whose three pools hold less than its reinvestment share blocks the approval (422).
     *
     * Any failure rolls everything back. Declaring locks the month's commission against recalculation.
     *
     * @throws ValidationException
     */
    public function approveDeclaration(DividendDeclarationRequest $request, Employee $approver): DividendDeclaration
    {
        $companyId = (int) $request->company_id;
        $period = CarbonImmutable::parse($request->period->toDateString())->startOfMonth();

        try {
            return DB::transaction(function () use ($request, $approver, $companyId, $period): DividendDeclaration {
                Company::query()->whereKey($companyId)->lockForUpdate()->firstOrFail();
                /** @var DividendDeclarationRequest $locked */
                $locked = DividendDeclarationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
                if (! $locked->isPending()) {
                    throw ValidationException::withMessages(['request' => 'Only a pending dividend declaration can be approved.']);
                }
                app(SegregationOfDuties::class)->assertCanApprove($locked->requested_by, $approver, 'dividend declaration', workflow: ApprovalPolicy::DIVIDEND_DECLARATIONS);

                $declarable = $this->declarable($companyId, $period, $locked->id);
                $computed = $declarable['computed'];
                $changed = DividendMath::toCents((string) $locked->profit_amount) !== $computed['profit']
                    || DividendMath::toCents((string) $locked->dividend_amount) !== $computed['pool']
                    || DividendMath::toCents((string) $locked->reinvest_amount) !== $computed['reinvest'];
                if ($changed) {
                    throw ValidationException::withMessages(['request' => 'The profit available for '.$period->format('F Y').' changed since this declaration was requested (requested TZS '.number_format((float) $locked->profit_amount, 2).', now TZS '.number_format(DividendMath::centsToFloat($computed['profit']), 2).'). Reject it and request the declaration again.']);
                }

                $declaration = $this->postDeclaration($companyId, $period, $declarable, (int) $locked->requested_by ?: null, $approver);

                $locked->update([
                    'status' => DividendDeclarationRequest::STATUS_APPROVED,
                    'pending_key' => null,
                    'approved_by' => $approver->id,
                    'approved_at' => now(),
                    'dividend_declaration_id' => $declaration->id,
                ]);
                $this->audit($locked, $approver, 'DividendDeclarationRequest.approved', DividendDeclarationRequest::STATUS_PENDING, DividendDeclarationRequest::STATUS_APPROVED, ['dividend_declaration_id' => $declaration->id]);

                return $declaration;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['period' => $this->alreadyDeclaredMessage($period)]);
        }
    }

    /**
     * REJECT a pending declaration request (nothing was posted; the request stays listed as REJECTED with the reason).
     *
     * @throws ValidationException
     */
    public function rejectDeclaration(DividendDeclarationRequest $request, string $reason, Employee $employee): DividendDeclarationRequest
    {
        return DB::transaction(function () use ($request, $reason, $employee): DividendDeclarationRequest {
            /** @var DividendDeclarationRequest $locked */
            $locked = DividendDeclarationRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['reason' => 'Only a pending dividend declaration can be rejected.']);
            }

            $locked->update([
                'status' => DividendDeclarationRequest::STATUS_REJECTED,
                'pending_key' => null,
                'rejected_by' => $employee->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->audit($locked, $employee, 'DividendDeclarationRequest.rejected', DividendDeclarationRequest::STATUS_PENDING, DividendDeclarationRequest::STATUS_REJECTED, ['reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Pending Approvals: dividend declaration requests of a company awaiting approval (oldest first).
     *
     * @return Collection<int, DividendDeclarationRequest>
     */
    public function pendingDeclarationRequests(int $companyId): Collection
    {
        return DividendDeclarationRequest::where('company_id', $companyId)
            ->where('status', DividendDeclarationRequest::STATUS_PENDING)
            ->with('requester')
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Every rule a declaration of the month must satisfy, in order (C1): not declared yet, no other pending request, period
     * CLOSED, commission CALCULATED, profit available, shareholders in the register, branch pools able to fund the
     * reinvestment. The caller holds the company row lock; the accounting period row is locked here so a concurrent
     * commission calculation serialises with it.
     *
     * @return array{period: AccountingPeriod, profit: array<string, mixed>, settings: array{dividend_percent: string, reinvest_percent: string}, as_of: CarbonImmutable, computed: array{profit: int, pool: int, reinvest: int, total_shares: int, rows: list<array<string, mixed>>}, branches: list<array<string, mixed>>}
     *
     * @throws ValidationException
     */
    private function declarable(int $companyId, CarbonImmutable $period, ?int $requestId): array
    {
        if (DividendDeclaration::where('company_id', $companyId)->whereDate('period', $period->toDateString())->exists()) {
            throw ValidationException::withMessages(['period' => $this->alreadyDeclaredMessage($period)]);
        }
        if (DividendDeclarationRequest::pendingFor($companyId, $period)->when($requestId !== null, fn ($query) => $query->whereKeyNot($requestId))->exists()) {
            throw ValidationException::withMessages(['period' => $this->pendingRequestMessage($period)]);
        }

        $accountingPeriod = $this->commission->closedPeriod($companyId, $period);
        if ($accountingPeriod === null) {
            throw ValidationException::withMessages(['period' => $this->notClosedMessage($period)]);
        }
        // Serialise with a concurrent commission calculation (it locks the same period row), so the base below
        // subtracts exactly the commission that is stored when the declaration commits.
        $accountingPeriod = AccountingPeriod::whereKey($accountingPeriod->id)->lockForUpdate()->firstOrFail();
        if (! $this->commission->isCalculated($accountingPeriod)) {
            throw ValidationException::withMessages(['period' => $this->commissionNotCalculatedMessage($period)]);
        }

        $profit = $this->availableProfit($companyId, $period);
        if ($profit['profit_available'] <= 0) {
            throw ValidationException::withMessages(['period' => "There is no profit available to distribute for {$profit['period_label']}."]);
        }

        $settings = $this->settings($companyId);
        $asOf = CarbonImmutable::today();
        $computed = $this->compute($companyId, $profit['profit_available'], $settings['dividend_percent'], $asOf);
        if ($computed['rows'] === []) {
            throw ValidationException::withMessages(['period' => 'No shareholder holds shares in the share register to receive a dividend.']);
        }

        $branches = $this->branchSplit($accountingPeriod, $computed['profit'], $computed['reinvest']);
        if (($shortfall = $this->shortfallMessage($branches)) !== null) {
            throw ValidationException::withMessages(['period' => $shortfall]);
        }

        return ['period' => $accountingPeriod, 'profit' => $profit, 'settings' => $settings, 'as_of' => $asOf, 'computed' => $computed, 'branches' => $branches];
    }

    /**
     * Post an approved declaration (see {@see approveDeclaration()}): declaration row, both journals, shareholder allocations.
     *
     * @param  array{period: AccountingPeriod, profit: array<string, mixed>, settings: array{dividend_percent: string, reinvest_percent: string}, as_of: CarbonImmutable, computed: array{profit: int, pool: int, reinvest: int, total_shares: int, rows: list<array<string, mixed>>}, branches: list<array<string, mixed>>}  $declarable
     */
    private function postDeclaration(int $companyId, CarbonImmutable $period, array $declarable, ?int $declaredBy, Employee $approver): DividendDeclaration
    {
        ['profit' => $profit, 'settings' => $settings, 'as_of' => $asOf, 'computed' => $computed, 'branches' => $branches] = $declarable;

        $profitAmount = DividendMath::fromCents($computed['profit']);
        $pool = DividendMath::fromCents($computed['pool']);
        $reinvest = DividendMath::fromCents($computed['reinvest']);
        $label = $period->format('Y-m');

        $declaration = DividendDeclaration::create([
            'company_id' => $companyId,
            'period' => $period->toDateString(),
            'profit_amount' => $profitAmount,
            'dividend_percent' => $settings['dividend_percent'],
            'dividend_amount' => $pool,
            'reinvest_percent' => $settings['reinvest_percent'],
            'reinvest_amount' => $reinvest,
            'total_shares' => $computed['total_shares'],
            'as_of_date' => $asOf->toDateString(),
            'profit_source' => $profit['source'],
            'allocation_rule' => self::RULE_PROFIT_ALLOCATION,
            'distributable_profit' => $profit['distributable_profit'],
            'commission_amount' => $profit['commission_amount'],
            'base_amount' => $profitAmount,
            'declared_by' => $declaredBy ?? $approver->id,
            'declared_at' => now(),
        ]);

        $lines = [];
        foreach ($branches as $branch) {
            $lines[] = ['account' => Account::RetainedProfit, 'branch' => $branch['branch_id'], 'debit' => $branch['base_amount']];
        }
        $lines[] = ['account' => Account::DividendPayable, 'credit' => (float) $pool];
        $lines[] = ['account' => Account::ReinvestedProfit, 'credit' => (float) $reinvest];
        $entry = $this->ledger->journal($companyId, 'DIVIDEND DECLARATION '.$label, $lines, $declaration, employee: $approver, type: TransactionType::DividendDeclaration);

        $moves = [];
        foreach ($branches as $branch) {
            if ($branch['reinvestment_amount'] <= 0) {
                continue;
            }
            // Reinvested profit becomes lending money, and lending money is HQ's: the branch income accounts are emptied
            // into the HQ PRINCIPAL A/C, never into a branch one.
            $moves[] = ['account' => Account::Principal, 'debit' => $branch['reinvestment_amount']];
            foreach ($branch['sources'] as $source) {
                $moves[] = ['account' => Account::from($source['account']), 'branch' => $branch['branch_id'], 'credit' => $source['amount']];
            }
        }
        $reinvestment = $moves === [] ? null : $this->ledger->journal($companyId, 'PROFIT REINVESTMENT '.$label, $moves, $declaration, employee: $approver, type: TransactionType::ProfitReinvestment);

        $declaration->update(['journal_entry_id' => $entry->id, 'reinvestment_journal_entry_id' => $reinvestment?->id]);

        foreach ($computed['rows'] as $row) {
            $declaration->allocations()->create([
                'company_id' => $companyId,
                'share_holder_id' => $row['share_holder']->id,
                'shares_held' => $row['shares'],
                'total_shares' => $row['total_shares'],
                'share_percent' => $row['percent'],
                'contribution_total' => $row['capital'],
                'amount' => DividendMath::fromCents($row['entitlement']),
                'paid_amount' => 0,
                'status' => DividendAllocation::STATUS_UNPAID,
            ]);
        }

        return $declaration->load('allocations.shareHolder');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function audit(DividendDeclarationRequest $request, Employee $employee, string $action, ?string $before, string $after, array $context = []): void
    {
        AuditLog::create([
            'company_id' => $request->company_id,
            'employee_id' => $employee->id,
            'action' => $action,
            'auditable_type' => $request->getMorphClass(),
            'auditable_id' => $request->id,
            'before' => $before === null ? null : ['status' => $before],
            'after' => ['status' => $after],
            'context' => $context + ['period' => $request->period->format('Y-m'), 'profit_amount' => (float) $request->profit_amount, 'dividend_amount' => (float) $request->dividend_amount, 'reinvest_amount' => (float) $request->reinvest_amount],
            'ip_address' => request()?->ip(),
        ]);
    }

    public function notClosedMessage(CarbonImmutable $period): string
    {
        return $period->format('F Y').' is not closed. Dividends can only be declared for a closed accounting period.';
    }

    /**
     * Branch split of a declaration base (D4): each eligible branch's weight is its distributable profit less its CALCULATED
     * commission (rule 5; a pool returned to profit stays in the weight, rule 4);
     * the base and the reinvestment are allocated by those weights to the cent (largest remainder, {@see DividendMath::allocate()}).
     * The reinvestment share is funded from the branch INTEREST, LOAN FEE and PENALTY A/C in that order (current balances).
     *
     * @return list<array{branch_id: int, branch: ?string, distributable_profit: float, commission_amount: float, weight: float, base_amount: float, reinvestment_amount: float, interest_pool: float, loan_fee_pool: float, penalty_pool: float, pools_total: float, shortfall: float, sources: list<array{account: string, amount: float}>}>
     */
    public function branchSplit(AccountingPeriod $period, int $baseCents, int $reinvestCents): array
    {
        $commission = $this->commission->commissionByBranch($period)['branches'];
        $results = $period->results()->with('branch:id,name')->orderBy('branch_id')->get()->filter(fn ($result): bool => (float) $result->distributable_profit > 0);

        $weights = [];
        foreach ($results as $result) {
            $weight = DividendMath::toCents((string) $result->distributable_profit) - DividendMath::toCents((float) ($commission[$result->branch_id] ?? 0));
            if ($weight > 0) {
                $weights[(int) $result->branch_id] = $weight;
            }
        }
        $total = array_sum($weights);
        $baseShares = DividendMath::allocate($baseCents, $weights, $total);
        $reinvestShares = DividendMath::allocate($reinvestCents, $weights, $total);

        $rows = [];
        foreach ($results->whereIn('branch_id', array_keys($weights)) as $result) {
            $branchId = (int) $result->branch_id;
            $share = DividendMath::centsToFloat($reinvestShares[$branchId] ?? 0);
            $pools = [
                Account::Interest->value => $this->ledger->balance($period->company_id, Account::Interest, $branchId),
                Account::LoanFee->value => $this->ledger->balance($period->company_id, Account::LoanFee, $branchId),
                Account::Penalty->value => $this->ledger->balance($period->company_id, Account::Penalty, $branchId),
            ];
            $left = DividendMath::toCents($share);
            $sources = [];
            foreach ($pools as $account => $available) {
                $take = min($left, max(0, DividendMath::toCents($available)));
                if ($take > 0) {
                    $sources[] = ['account' => $account, 'amount' => DividendMath::centsToFloat($take)];
                    $left -= $take;
                }
            }

            $rows[] = [
                'branch_id' => $branchId,
                'branch' => $result->branch?->name,
                'distributable_profit' => (float) $result->distributable_profit,
                'commission_amount' => (float) ($commission[$branchId] ?? 0),
                'weight' => DividendMath::centsToFloat($weights[$branchId]),
                'base_amount' => DividendMath::centsToFloat($baseShares[$branchId] ?? 0),
                'reinvestment_amount' => $share,
                'interest_pool' => $pools[Account::Interest->value],
                'loan_fee_pool' => $pools[Account::LoanFee->value],
                'penalty_pool' => $pools[Account::Penalty->value],
                'pools_total' => round(array_sum($pools), 2),
                'shortfall' => DividendMath::centsToFloat(max(0, $left)),
                'sources' => $sources,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array{branch: ?string, shortfall: float, reinvestment_amount: float, pools_total: float}>  $branches
     */
    private function shortfallMessage(array $branches): ?string
    {
        $short = array_filter($branches, fn (array $branch): bool => $branch['shortfall'] > 0);
        if ($short === []) {
            return null;
        }

        return 'Principal reinvestment cannot be funded: '.implode('; ', array_map(
            fn (array $branch): string => "{$branch['branch']} needs TZS ".number_format($branch['reinvestment_amount'], 2).' but its INTEREST, LOAN FEE and PENALTY A/C hold TZS '.number_format(max(0, $branch['pools_total']), 2).' (shortfall TZS '.number_format($branch['shortfall'], 2).')',
            $short,
        )).'.';
    }

    /**
     * Pay all or part of a shareholder's remaining entitlement. A request carrying an idempotency key that was already
     * used returns the original payment instead of posting again.
     *
     * @return array{payment: DividendPayment, created: bool}
     *
     * @throws ValidationException
     */
    public function pay(
        DividendAllocation $allocation,
        string|float $amount,
        string $method,
        ?int $bankAccountId,
        ?string $reference,
        Employee $employee,
        ?string $idempotencyKey = null,
    ): array {
        $cents = DividendMath::toCents(is_float($amount) ? $amount : (string) $amount);
        if ($cents <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount to pay must be greater than zero.']);
        }

        $previous = $this->replayPayment($allocation, $cents, $idempotencyKey);
        if ($previous !== null) {
            return ['payment' => $previous, 'created' => false];
        }

        $source = $this->sourceAccount((int) $allocation->company_id, $method, $bankAccountId);

        try {
            $payment = DB::transaction(function () use ($allocation, $cents, $method, $source, $reference, $employee, $idempotencyKey): DividendPayment {
                /** @var DividendAllocation $locked */
                $locked = DividendAllocation::whereKey($allocation->id)->lockForUpdate()->firstOrFail();

                return $this->postPayment($locked, $cents, $method, $source, $reference, $employee, $idempotencyKey);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $this->replayPayment($allocation, $cents, $idempotencyKey);
            if ($previous === null) {
                throw $exception;
            }

            return ['payment' => $previous, 'created' => false];
        }

        return ['payment' => $payment, 'created' => true];
    }

    /**
     * What PAY ALL OUTSTANDING would pay for a declaration right now: every allocation with a balance, its balance and
     * the total — read from the posted payments.
     *
     * @return array{declaration_id: int, period: string, period_label: string, shareholders: int, paid_shareholders: int, total_entitlement: float, total_paid: float, total_outstanding: float, rows: list<array{allocation_id: int, share_holder_id: int, share_holder: ?string, entitlement: float, paid_amount: float, balance: float}>}
     */
    public function payAllPreview(DividendDeclaration $declaration): array
    {
        $allocations = $declaration->allocations()->with('shareHolder')->orderBy('id')->get();

        return $this->outstandingSummary($declaration, $allocations);
    }

    /**
     * PAY ALL OUTSTANDING: in ONE transaction, lock the declaration and all its allocations (ordered by id), re-read each
     * paid total from the posted payments and pay exactly each remaining balance through the same posting path as
     * {@see pay()} (one payment row and one journal entry per shareholder), grouped in a batch. Fully paid allocations are
     * skipped. Any failure rolls the whole batch back. The client's expected total must match the server's total, but
     * the server-computed balances are what gets paid. A repeated idempotency key returns the original batch.
     *
     * @return array{batch: DividendPaymentBatch, created: bool}
     *
     * @throws ValidationException
     */
    public function payAll(
        DividendDeclaration $declaration,
        string|float|null $expectedTotal,
        string $method,
        ?int $bankAccountId,
        ?string $reference,
        Employee $employee,
        ?string $idempotencyKey = null,
    ): array {
        $previous = $this->replayBatch($declaration, $idempotencyKey);
        if ($previous !== null) {
            return ['batch' => $previous, 'created' => false];
        }

        $companyId = (int) $declaration->company_id;
        $source = $this->sourceAccount($companyId, $method, $bankAccountId);

        try {
            $batch = DB::transaction(function () use ($declaration, $companyId, $expectedTotal, $method, $source, $reference, $employee, $idempotencyKey): DividendPaymentBatch {
                /** @var DividendDeclaration $lockedDeclaration */
                $lockedDeclaration = DividendDeclaration::whereKey($declaration->id)->lockForUpdate()->firstOrFail();
                $allocations = DividendAllocation::where('dividend_declaration_id', $lockedDeclaration->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $allocations->load('shareHolder');

                $summary = $this->outstandingSummary($lockedDeclaration, $allocations);
                $totalCents = DividendMath::toCents($summary['total_outstanding']);
                if ($totalCents <= 0) {
                    throw ValidationException::withMessages(['expected_total' => "All dividends for {$summary['period_label']} are already paid; there is no outstanding balance."]);
                }
                if ($expectedTotal === null || DividendMath::toCents(is_float($expectedTotal) ? $expectedTotal : (string) $expectedTotal) !== $totalCents) {
                    throw ValidationException::withMessages(['expected_total' => 'Outstanding balances changed — review and try again.']);
                }

                $total = DividendMath::centsToFloat($totalCents);
                $available = $this->ledger->balance($companyId, $source['account'], bankAccount: $source['bank'] ?? null);
                if ($available + 0.001 < $total) {
                    throw ValidationException::withMessages(['pay_method' => 'Insufficient balance in '.$source['label']]);
                }

                $batch = DividendPaymentBatch::create([
                    'company_id' => $companyId,
                    'dividend_declaration_id' => $lockedDeclaration->id,
                    'pay_method' => $method === 'BANK' ? 'BANK' : 'CASH',
                    'bank_account_id' => $source['bank'] ?? null,
                    'reference' => $reference,
                    'total_amount' => DividendMath::fromCents($totalCents),
                    'payments_count' => 0,
                    'idempotency_key' => $idempotencyKey,
                    'paid_by' => $employee->id,
                    'paid_at' => now(),
                ]);
                $batch->update(['batch_reference' => sprintf('DIVB-%s-%06d', $lockedDeclaration->period->format('Ym'), $batch->id)]);

                $balances = collect($summary['rows'])->keyBy('allocation_id');
                $count = 0;
                foreach ($allocations as $allocation) {
                    $balanceCents = DividendMath::toCents($balances[$allocation->id]['balance'] ?? 0);
                    if ($balanceCents <= 0) {
                        continue;
                    }
                    $allocation->setRelation('declaration', $lockedDeclaration);
                    $this->postPayment($allocation, $balanceCents, $method, $source, $reference, $employee, null, $batch->id);
                    $count++;
                }

                $batch->update(['payments_count' => $count]);

                return $batch;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $this->replayBatch($declaration, $idempotencyKey);
            if ($previous === null) {
                throw $exception;
            }

            return ['batch' => $previous, 'created' => false];
        }

        return ['batch' => $batch, 'created' => true];
    }

    /**
     * List flags for a payment row: whether the viewer may reverse it now and, when a permitted viewer cannot, why
     * (rule 6: the employee who posted the payment journal does not reverse it). Reversed rows and viewers without the
     * permission carry no reason (the action is not offered).
     *
     * @return array{can_reverse: bool, reverse_blocked_reason: string|null}
     */
    public function reverseFlags(DividendPayment $payment, ?Employee $viewer, bool $permitted): array
    {
        if (! $payment->isPosted() || ! $permitted) {
            return ['can_reverse' => false, 'reverse_blocked_reason' => null];
        }

        $entry = $payment->journalEntry;
        $reason = $entry === null ? 'This dividend payment has no journal entry to reverse.' : null;
        if ($reason === null && $viewer !== null) {
            $reason = app(SegregationOfDuties::class)->reverseBlockedReason($entry, $viewer);
        }

        return ['can_reverse' => $reason === null, 'reverse_blocked_reason' => $reason];
    }

    /**
     * Reverse a posted payment: opposite journal entry, payment marked reversed, entitlement balance restored.
     *
     * @throws ValidationException
     */
    public function reversePayment(DividendPayment $payment, string $reason, Employee $employee): DividendPayment
    {
        return DB::transaction(function () use ($payment, $reason, $employee): DividendPayment {
            $allocation = DividendAllocation::whereKey($payment->dividend_allocation_id)->lockForUpdate()->firstOrFail();
            /** @var DividendPayment $locked */
            $locked = DividendPayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isPosted()) {
                throw ValidationException::withMessages(['reason' => 'This dividend payment has already been reversed.']);
            }
            if ($locked->journalEntry === null) {
                throw ValidationException::withMessages(['reason' => 'This dividend payment has no journal entry to reverse.']);
            }

            app(SegregationOfDuties::class)->assertCanReverse($locked->journalEntry, $employee);
            $reversal = $this->ledger->reverse($locked->journalEntry, $reason);

            $locked->update([
                'status' => DividendPayment::STATUS_REVERSED,
                'reversal_journal_entry_id' => $reversal->id,
                'reversed_by' => $employee->id,
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ]);
            $this->refreshAllocation($allocation);

            return $locked;
        });
    }

    /**
     * Rewrite an allocation's paid amount and status from its posted payments.
     */
    public function refreshAllocation(DividendAllocation $allocation): DividendAllocation
    {
        $paid = $this->postedCents($allocation);
        $allocation->update([
            'paid_amount' => DividendMath::fromCents($paid),
            'status' => DividendAllocation::statusFor(DividendMath::toCents((string) $allocation->amount), $paid),
        ]);

        return $allocation;
    }

    /**
     * Sum of the allocation's posted (not reversed) payments, in cents, read from the payments table.
     */
    public function postedCents(DividendAllocation $allocation): int
    {
        $sum = DividendPayment::where('dividend_allocation_id', $allocation->id)
            ->where('status', DividendPayment::STATUS_POSTED)
            ->sum('amount');

        return DividendMath::toCents((string) $sum);
    }

    /**
     * Company totals: declared pools, posted payments and the outstanding balance.
     *
     * @return array{total_declared: float, total_paid: float, total_outstanding: float, dividend_balance: float, declarations: int}
     */
    public function totals(int $companyId): array
    {
        $declared = DividendMath::toCents((string) DividendDeclaration::where('company_id', $companyId)->sum('dividend_amount'));
        $paid = DividendMath::toCents((string) DividendPayment::where('company_id', $companyId)->where('status', DividendPayment::STATUS_POSTED)->sum('amount'));

        return [
            'total_declared' => DividendMath::centsToFloat($declared),
            'total_paid' => DividendMath::centsToFloat($paid),
            'total_outstanding' => DividendMath::centsToFloat($declared - $paid),
            'dividend_balance' => $this->dividendBalance($companyId),
            'declarations' => DividendDeclaration::where('company_id', $companyId)->count(),
        ];
    }

    public function alreadyDeclaredMessage(CarbonImmutable $period): string
    {
        return 'Dividends for '.$period->format('F Y').' have already been declared.';
    }

    /**
     * COMPANY ACCOUNT for CASH; for BANK a bank account of the same company is required.
     *
     * @return array{account: Account, bank?: int, label: string}
     */
    private function sourceAccount(int $companyId, string $method, ?int $bankAccountId): array
    {
        if ($method !== 'BANK') {
            return ['account' => Account::Company, 'label' => Account::Company->label()];
        }

        $bank = $bankAccountId === null ? null : BankAccount::where('company_id', $companyId)->find($bankAccountId);
        if ($bank === null) {
            throw ValidationException::withMessages(['bank_account_id' => 'Select the company bank account to pay from.']);
        }

        return ['account' => Account::Bank, 'bank' => $bank->id, 'label' => $bank->name];
    }

    /**
     * Post one payment of a LOCKED allocation (the caller holds the row lock inside a transaction): re-check the balance
     * from the posted payments, check the source balance, write the payment row and its journal entry (Dr DIVIDEND
     * ACCOUNT / Cr COMPANY ACCOUNT or bank), then rewrite the allocation's paid amount and status.
     *
     * @param  array{account: Account, bank?: int, label: string}  $source
     *
     * @throws ValidationException
     */
    private function postPayment(
        DividendAllocation $locked,
        int $cents,
        string $method,
        array $source,
        ?string $reference,
        Employee $employee,
        ?string $idempotencyKey,
        ?int $batchId = null,
    ): DividendPayment {
        $locked->loadMissing('shareHolder', 'declaration');

        $entitlement = DividendMath::toCents((string) $locked->amount);
        $remaining = $entitlement - $this->postedCents($locked);
        if ($remaining <= 0) {
            throw ValidationException::withMessages(['amount' => 'This dividend entitlement is already fully paid.']);
        }
        if ($cents > $remaining) {
            throw ValidationException::withMessages(['amount' => 'The amount to pay cannot exceed the outstanding balance of TZS '.number_format(DividendMath::centsToFloat($remaining), 2).'.']);
        }

        $amount = DividendMath::centsToFloat($cents);
        $balance = $this->ledger->balance($locked->company_id, $source['account'], bankAccount: $source['bank'] ?? null);
        if ($balance + 0.001 < $amount) {
            throw ValidationException::withMessages(['pay_method' => 'Insufficient balance in '.$source['label']]);
        }

        $payment = DividendPayment::create([
            'company_id' => $locked->company_id,
            'dividend_allocation_id' => $locked->id,
            'share_holder_id' => $locked->share_holder_id,
            'dividend_payment_batch_id' => $batchId,
            'amount' => DividendMath::fromCents($cents),
            'pay_method' => $method === 'BANK' ? 'BANK' : 'CASH',
            'source_account' => $source['account']->value,
            'bank_account_id' => $source['bank'] ?? null,
            'reference' => $reference,
            'paid_at' => now(),
            'paid_by' => $employee->id,
            'idempotency_key' => $idempotencyKey,
            'status' => DividendPayment::STATUS_POSTED,
        ]);

        $entry = $this->ledger->journal($locked->company_id, 'DIVIDEND PAYMENT '.$locked->declaration->period->format('Y-m').' - '.$locked->shareHolder->full_name, [
            ['account' => Account::DividendPayable, 'debit' => $amount],
            ['account' => $source['account'], 'bank' => $source['bank'] ?? null, 'credit' => $amount],
        ], $payment, employee: $employee);

        $payment->update(['journal_entry_id' => $entry->id]);
        $this->refreshAllocation($locked);

        return $payment;
    }

    /**
     * Entitlement, posted payments and balance of each allocation (one grouped query on the payments table) and the
     * declaration totals.
     *
     * @param  Collection<int, DividendAllocation>  $allocations
     * @return array{declaration_id: int, period: string, period_label: string, shareholders: int, paid_shareholders: int, total_entitlement: float, total_paid: float, total_outstanding: float, rows: list<array{allocation_id: int, share_holder_id: int, share_holder: ?string, entitlement: float, paid_amount: float, balance: float}>}
     */
    private function outstandingSummary(DividendDeclaration $declaration, Collection $allocations): array
    {
        $posted = $this->postedCentsByAllocation($allocations->pluck('id')->all());
        $rows = [];
        $entitlementTotal = 0;
        $paidTotal = 0;
        $outstandingTotal = 0;
        $paidShareholders = 0;

        foreach ($allocations as $allocation) {
            $entitlement = DividendMath::toCents((string) $allocation->amount);
            $paid = $posted[$allocation->id] ?? 0;
            $balance = max(0, $entitlement - $paid);
            $entitlementTotal += $entitlement;
            $paidTotal += $paid;
            if ($balance <= 0) {
                $paidShareholders++;

                continue;
            }
            $outstandingTotal += $balance;
            $rows[] = [
                'allocation_id' => $allocation->id,
                'share_holder_id' => (int) $allocation->share_holder_id,
                'share_holder' => $allocation->shareHolder?->full_name,
                'entitlement' => DividendMath::centsToFloat($entitlement),
                'paid_amount' => DividendMath::centsToFloat($paid),
                'balance' => DividendMath::centsToFloat($balance),
            ];
        }

        return [
            'declaration_id' => $declaration->id,
            'period' => $declaration->period->format('Y-m'),
            'period_label' => $declaration->periodLabel(),
            'shareholders' => count($rows),
            'paid_shareholders' => $paidShareholders,
            'total_entitlement' => DividendMath::centsToFloat($entitlementTotal),
            'total_paid' => DividendMath::centsToFloat($paidTotal),
            'total_outstanding' => DividendMath::centsToFloat($outstandingTotal),
            'rows' => $rows,
        ];
    }

    /**
     * Posted (not reversed) payment totals in cents, keyed by allocation id.
     *
     * @param  list<int>  $allocationIds
     * @return array<int, int>
     */
    public function postedCentsByAllocation(array $allocationIds): array
    {
        if ($allocationIds === []) {
            return [];
        }

        return DividendPayment::whereIn('dividend_allocation_id', $allocationIds)
            ->where('status', DividendPayment::STATUS_POSTED)
            ->groupBy('dividend_allocation_id')
            ->selectRaw('dividend_allocation_id, SUM(amount) as total')
            ->pluck('total', 'dividend_allocation_id')
            ->map(fn ($total): int => DividendMath::toCents((string) $total))
            ->all();
    }

    /**
     * The batch already recorded under this idempotency key, if any.
     */
    private function replayBatch(DividendDeclaration $declaration, ?string $idempotencyKey): ?DividendPaymentBatch
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        $previous = DividendPaymentBatch::where('idempotency_key', $idempotencyKey)->first();
        if ($previous === null) {
            return null;
        }

        if ((int) $previous->dividend_declaration_id !== (int) $declaration->id) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different dividend batch.']);
        }

        return $previous;
    }

    /**
     * The payment already recorded under this idempotency key, if any.
     */
    private function replayPayment(DividendAllocation $allocation, int $cents, ?string $idempotencyKey): ?DividendPayment
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return null;
        }

        $previous = DividendPayment::where('idempotency_key', $idempotencyKey)->first();
        if ($previous === null) {
            return null;
        }

        if ((int) $previous->dividend_allocation_id !== (int) $allocation->id || DividendMath::toCents((string) $previous->amount) !== $cents) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different dividend payment.']);
        }

        return $previous;
    }

    /**
     * Profit split and entitlements in cents for a profit figure, a shareholder percentage and an ownership date.
     *
     * @return array{profit: int, pool: int, reinvest: int, total_shares: int, rows: list<array{share_holder: ShareHolder, capital: float, shares: int, total_shares: int, percent: float, entitlement: int}>}
     */
    private function compute(int $companyId, float $profit, string $dividendPercent, CarbonImmutable $asOf): array
    {
        $profitCents = max(0, DividendMath::toCents($profit));
        $split = DividendMath::split($profitCents, DividendMath::percentToBasis($dividendPercent));

        $holders = $this->shares($companyId, $asOf)->filter(fn (array $share): bool => $share['shares'] > 0)->values();
        $totalShares = (int) $holders->sum('shares');
        $entitlements = DividendMath::allocate($split['pool'], $holders->mapWithKeys(fn (array $share): array => [$share['share_holder']->id => $share['shares']])->all(), $totalShares);

        return [
            'profit' => $profitCents,
            'pool' => $split['pool'],
            'reinvest' => $split['reinvest'],
            'total_shares' => $totalShares,
            'rows' => $holders->map(fn (array $share): array => $share + ['entitlement' => $entitlements[$share['share_holder']->id] ?? 0])->all(),
        ];
    }
}
