<?php

namespace App\Services\Approvals;

use App\Enums\Account;
use App\Enums\HqFund;
use App\Enums\LoanStatus;
use App\Enums\StaffCreditStatus;
use App\Models\ApprovalPolicy;
use App\Models\BankTransfer;
use App\Models\Capital;
use App\Models\DividendDeclarationRequest;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\FloatTransfer;
use App\Models\HqTransaction;
use App\Models\LegacyImport;
use App\Models\Loan;
use App\Models\NegligenceDeduction;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\ReversalRequest;
use App\Models\SalaryAdvance;
use App\Models\ShareIssuanceRequest;
use App\Models\StaffAllowance;
use App\Models\StaffLoan;
use App\Models\StaffSalaryAdvance;
use App\Models\TellerDeposit;
use App\Models\WriteOffRequest;
use App\Services\AccessControl;
use App\Services\CapitalContributions;
use App\Services\CompanyFunds;
use App\Services\DividendService;
use App\Services\ExpenseApproval;
use App\Services\FloatService;
use App\Services\Hrm\StaffCredit;
use App\Services\LegacyImports\LegacyImportService;
use App\Services\LoanService;
use App\Services\LoanWorkflow;
use App\Services\PaymentService;
use App\Services\ReversalRequests;
use App\Services\ShareholderAccounts;
use App\Services\Shares\ShareIssuance;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * C6 Pending Approvals: one read-only list of every item waiting for a checker, across the two-step workflows. Each workflow
 * is included only for a viewer holding that module's permission; rows are limited to the viewer's company and — for branch
 * data — to their branch scope ({@see AccessControl::branchIds()}), as on the module pages. Approving still happens on the
 * module page (`link`); `can_approve` / `approve_blocked_reason` carry the module's approval permission and the
 * segregation-of-duties rule including the company approval policy ({@see SegregationOfDuties}).
 */
class PendingApprovals
{
    /**
     * Group key of staff allowances waiting for Finance (spec §24 / §58). Approval follows the {@see ApprovalPolicy::PAYROLL} policy.
     */
    public const ALLOWANCES = 'hrm.allowance';

    /**
     * Group key of negligence / loss deductions waiting for Finance (spec §23 / §57). Approval follows the {@see ApprovalPolicy::PAYROLL} policy.
     */
    public const NEGLIGENCE_DEDUCTIONS = 'hrm.negligence_deduction';

    /**
     * Group key of old-system files (Loan File, Penalty List, Active Salary Advance) waiting for approval. The uploader never
     * approves their own import, the Super Admin included.
     */
    public const LEGACY_IMPORTS = 'legacy_imports.approval';

    /**
     * Group key => heading, in display order.
     *
     * @var array<string, string>
     */
    public const GROUPS = [
        ApprovalPolicy::EXPENSES => 'Expenses',
        ApprovalPolicy::FLOATS => 'Floats',
        ApprovalPolicy::BANK_TRANSFERS => 'Bank Transfers',
        ApprovalPolicy::HQ_TRANSACTIONS => 'HQ Transactions',
        ApprovalPolicy::CAPITAL_CONTRIBUTIONS => 'Capital Contributions',
        ApprovalPolicy::ASSET_CONTRIBUTIONS => 'Asset Contributions',
        ApprovalPolicy::SHARE_ISSUANCES => 'Share Issuances',
        ApprovalPolicy::DIVIDEND_DECLARATIONS => 'Dividend Declarations',
        ApprovalPolicy::LOAN_APPROVALS => 'Loan Approvals',
        ApprovalPolicy::WRITE_OFFS => 'Loan Write-offs',
        ApprovalPolicy::REVERSAL_REQUESTS => 'Reversals',
        self::LEGACY_IMPORTS => 'Old System Imports',
        ApprovalPolicy::SALARY_ADVANCES => 'Salary Advances',
        ApprovalPolicy::STAFF_CREDIT => 'Staff Loans & Salary Advances',
        ApprovalPolicy::PAYROLL => 'Payroll',
        self::ALLOWANCES => 'Staff Allowances',
        self::NEGLIGENCE_DEDUCTIONS => 'Negligence / Loss Deductions',
        ApprovalPolicy::TELLER_DEPOSITS => 'Teller Deposits',
        ApprovalPolicy::BRANCH_RECEIPTS => 'Branch Receipts',
    ];

    private Employee $viewer;

    /**
     * @var list<int>|null
     */
    private ?array $branchIds;

    /**
     * @var list<string>
     */
    private array $permissions;

    public function __construct(
        private readonly AccessControl $access,
        private readonly SegregationOfDuties $duties,
    ) {}

    /**
     * @return array{groups: list<array{workflow: string, label: string, count: int, amount: float, rows: list<array<string, mixed>>}>, total_count: int, total_amount: float}
     */
    public function forEmployee(Employee $viewer): array
    {
        $this->viewer = $viewer;
        $this->branchIds = $this->access->branchIds($viewer);
        $this->permissions = $this->access->permissionsFor($viewer);

        $groups = [];
        foreach ($this->collectors() as $workflow => $collector) {
            $rows = $collector();
            if ($rows === null) {
                continue;
            }

            usort($rows, fn (array $a, array $b): int => [$a['requested_at'] ?? '', $a['id']] <=> [$b['requested_at'] ?? '', $b['id']]);
            $groups[] = [
                'workflow' => $workflow,
                'label' => self::GROUPS[$workflow],
                'count' => count($rows),
                'amount' => round(array_sum(array_column($rows, 'amount')), 2),
                'rows' => $rows,
            ];
        }

        return [
            'groups' => $groups,
            'total_count' => array_sum(array_column($groups, 'count')),
            'total_amount' => round(array_sum(array_column($groups, 'amount')), 2),
        ];
    }

    /**
     * Workflow => collector returning its rows, or null when the viewer may not see the workflow.
     *
     * @return array<string, callable(): (list<array<string, mixed>>|null)>
     */
    private function collectors(): array
    {
        return [
            ApprovalPolicy::EXPENSES => fn (): ?array => $this->expenses(),
            ApprovalPolicy::FLOATS => fn (): ?array => $this->floats(),
            ApprovalPolicy::BANK_TRANSFERS => fn (): ?array => $this->bankTransfers(),
            ApprovalPolicy::HQ_TRANSACTIONS => fn (): ?array => $this->hqTransactions(),
            ApprovalPolicy::CAPITAL_CONTRIBUTIONS => fn (): ?array => $this->capitalContributions(),
            ApprovalPolicy::ASSET_CONTRIBUTIONS => fn (): ?array => $this->assetContributions(),
            ApprovalPolicy::SHARE_ISSUANCES => fn (): ?array => $this->shareIssuances(),
            ApprovalPolicy::DIVIDEND_DECLARATIONS => fn (): ?array => $this->dividendDeclarations(),
            ApprovalPolicy::LOAN_APPROVALS => fn (): ?array => $this->loanApprovals(),
            ApprovalPolicy::WRITE_OFFS => fn (): ?array => $this->writeOffs(),
            ApprovalPolicy::REVERSAL_REQUESTS => fn (): ?array => $this->reversalRequests(),
            self::LEGACY_IMPORTS => fn (): ?array => $this->legacyImports(),
            ApprovalPolicy::SALARY_ADVANCES => fn (): ?array => $this->salaryAdvances(),
            ApprovalPolicy::STAFF_CREDIT => fn (): ?array => $this->staffCredit(),
            ApprovalPolicy::PAYROLL => fn (): ?array => $this->payroll(),
            self::ALLOWANCES => fn (): ?array => $this->allowances(),
            self::NEGLIGENCE_DEDUCTIONS => fn (): ?array => $this->negligenceDeductions(),
            ApprovalPolicy::TELLER_DEPOSITS => fn (): ?array => $this->tellerDeposits(),
            ApprovalPolicy::BRANCH_RECEIPTS => fn (): ?array => $this->branchReceipts(),
        ];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function expenses(): ?array
    {
        $views = [
            'branch' => ['expenses.request', 'expenses.approve_branch', 'expenses.approve_hq', 'reports.financial'],
            'hq' => ['hq.manage', 'expenses.approve_hq'],
            'bank' => ['bank.manage'],
        ];
        $scopes = array_keys(array_filter($views, fn (array $permissions): bool => $this->canAny(...$permissions)));
        if ($scopes === []) {
            return null;
        }

        $links = ['branch' => '/expenses/requests', 'hq' => '/hq-expenses/requests', 'bank' => '/bank/expenses'];
        $approval = app(ExpenseApproval::class);

        return ExpenseRequest::where('company_id', $this->viewer->company_id)
            ->where('status', 'pending')
            ->whereIn('scope', $scopes)
            ->when($this->branchIds !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->where('scope', '!=', 'branch')->orWhereIn('branch_id', $this->branchIds)))
            ->with(['branch', 'employee', 'expenseType'])
            ->get()
            ->map(fn (ExpenseRequest $row): array => $this->row(
                ApprovalPolicy::EXPENSES,
                $row->id,
                strtoupper((string) $row->scope).' EXPENSE — '.($row->expenseType?->name ?? ''),
                $row->branch?->name,
                (float) $row->amount,
                $row->employee?->full_name,
                $row->created_at,
                'pending',
                $links[$row->scope] ?? '/expenses/requests',
                $row->employee_id,
                $this->canAny(...$approval->requiredPermissions($row, (float) $row->amount)),
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function floats(): ?array
    {
        if (! $this->canAny('float.manage')) {
            return null;
        }

        $links = ['company_to_hq' => '/capital/floats', 'company_to_branch' => '/capital/floats'];

        return FloatTransfer::where('company_id', $this->viewer->company_id)
            ->where('status', FloatService::PENDING)
            ->when($this->branchIds !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereIn('from_branch_id', $this->branchIds)->orWhereIn('to_branch_id', $this->branchIds)))
            ->with(['fromBranch', 'toBranch', 'requester'])
            ->get()
            ->map(fn (FloatTransfer $row): array => $this->row(
                ApprovalPolicy::FLOATS,
                $row->id,
                trim(strtoupper(str_replace('_', ' ', (string) $row->type)).' — '.implode(' → ', array_filter([
                    $row->fromBranch?->name ?? ($row->from_account ? Account::tryFrom($row->from_account)?->label() : null),
                    $row->toBranch?->name ?? ($row->to_account ? Account::tryFrom($row->to_account)?->label() : null),
                ]))),
                $row->toBranch?->name ?? $row->fromBranch?->name,
                (float) $row->amount,
                $row->requester?->full_name,
                $row->created_at,
                'pending',
                $links[$row->type] ?? '/capital/floats',
                $row->requested_by,
                FloatService::canTransfer($this->viewer),
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function bankTransfers(): ?array
    {
        if (! $this->canAny('bank.manage')) {
            return null;
        }

        $links = [
            CompanyFunds::CASH_TO_BANK => '/bank/company-transfers',
            CompanyFunds::BANK_TO_CASH => '/bank/company-transfers',
            CompanyFunds::RESERVE_TO_INVESTMENT => '/bank/reserve-to-investment',
            CompanyFunds::RESERVE_TO_PRINCIPAL => '/bank/reserve-to-principal',
            CompanyFunds::PETTY_CASH_TO_BRANCH => '/bank/petty-cash',
        ];

        // Only the live movement types: a type with no screen left (the retired branch → bank sweep) has no link to
        // follow and can no longer be posted, so it must not be offered for approval.
        return BankTransfer::where('company_id', $this->viewer->company_id)
            ->whereIn('type', array_keys($links))
            ->where('status', CompanyFunds::PENDING)
            ->when($this->branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $this->branchIds))
            ->with(['branch', 'bankAccount', 'employee'])
            ->get()
            ->map(fn (BankTransfer $row): array => $this->row(
                ApprovalPolicy::BANK_TRANSFERS,
                $row->id,
                strtoupper(str_replace('_', ' ', (string) $row->type)).' — '.($row->bankAccount?->name ?? ''),
                $row->branch?->name,
                (float) $row->amount,
                $row->employee?->full_name,
                $row->created_at,
                'pending',
                $links[$row->type],
                $row->employee_id,
                ! in_array($row->type, [CompanyFunds::RESERVE_TO_INVESTMENT, CompanyFunds::RESERVE_TO_PRINCIPAL], true) || CompanyFunds::canDecideReserve($this->viewer),
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function hqTransactions(): ?array
    {
        if (! $this->canAny('hq.manage')) {
            return null;
        }

        return HqTransaction::where('company_id', $this->viewer->company_id)
            ->where('status', 'pending')
            ->with(['employee', 'bankAccount'])
            ->get()
            ->map(fn (HqTransaction $row): array => $this->row(
                ApprovalPolicy::HQ_TRANSACTIONS,
                $row->id,
                $this->hqAccountName((string) $row->from_account).' → '.($row->bankAccount?->name ?? $this->hqAccountName((string) $row->to_account)),
                null,
                (float) $row->amount,
                $row->employee?->full_name,
                $row->created_at,
                'pending',
                '/hq/transactions',
                $row->employee_id,
                true,
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function capitalContributions(): ?array
    {
        if (! $this->canAny('capital.view', 'capital.manage')) {
            return null;
        }

        $contributions = app(CapitalContributions::class);

        return Capital::where('company_id', $this->viewer->company_id)
            ->where('status', Capital::STATUS_PENDING)
            ->where('pay_method', '!=', 'ASSET')
            ->with(['shareHolder', 'recorder'])
            ->get()
            ->map(fn (Capital $row): array => $this->row(
                ApprovalPolicy::CAPITAL_CONTRIBUTIONS,
                $row->id,
                $row->pay_method.' CAPITAL — '.$row->shareHolder?->full_name.($row->isFromShareholderPortal() ? ' (submitted by shareholder)' : ''),
                null,
                (float) $row->amount,
                $row->recorder?->full_name ?? $row->shareHolder?->full_name,
                $row->created_at,
                'pending',
                '/capital/capitals',
                $contributions->initiatorIds($row),
                $this->canAny('capital.manage'),
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function assetContributions(): ?array
    {
        if (! $this->canAny('capital.view', 'capital.manage')) {
            return null;
        }

        $contributions = app(CapitalContributions::class);

        return Capital::where('company_id', $this->viewer->company_id)
            ->where('status', Capital::STATUS_PENDING)
            ->where('pay_method', 'ASSET')
            ->whereHas('asset')
            ->with(['shareHolder', 'recorder', 'asset.branch'])
            ->get()
            ->map(fn (Capital $row): array => $this->row(
                ApprovalPolicy::ASSET_CONTRIBUTIONS,
                $row->asset->id,
                trim(($row->asset->asset_code ?? '').' '.$row->asset->name).' — '.$row->shareHolder?->full_name,
                $row->asset->branch?->name,
                (float) $row->amount,
                $row->recorder?->full_name,
                $row->created_at,
                'pending',
                "/capital/assets/{$row->asset->id}",
                $contributions->initiatorIds($row),
                $this->canAny('capital.manage'),
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function shareIssuances(): ?array
    {
        if (! $this->canAny('shares.view', 'shares.issue')) {
            return null;
        }

        $issuance = app(ShareIssuance::class);

        return $issuance->pendingIssuanceRequests((int) $this->viewer->company_id)
            ->map(fn (ShareIssuanceRequest $row): array => $this->row(
                ApprovalPolicy::SHARE_ISSUANCES,
                $row->id,
                number_format((int) $row->shares).' SHARES — '.$row->shareHolder?->full_name.' ('.$row->pay_method.')',
                null,
                (float) $row->total_amount,
                $row->requester?->full_name,
                $row->created_at,
                'pending',
                '/shares/issue',
                $issuance->requestInitiatorIds($row),
                $this->canAny('shares.issue'),
            ))->values()->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function dividendDeclarations(): ?array
    {
        if (! $this->canAny('capital.manage')) {
            return null;
        }

        return app(DividendService::class)->pendingDeclarationRequests((int) $this->viewer->company_id)
            ->map(fn (DividendDeclarationRequest $row): array => $this->row(
                ApprovalPolicy::DIVIDEND_DECLARATIONS,
                $row->id,
                'DIVIDEND '.$row->period?->format('F Y'),
                null,
                (float) $row->dividend_amount,
                $row->requester?->full_name,
                $row->requested_at ?? $row->created_at,
                'pending',
                '/capital/dividends',
                $row->requested_by,
                true,
            ))->values()->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function loanApprovals(): ?array
    {
        $stages = [
            LoanStatus::PendingManagerApproval->value => ['permission' => 'loans.approve_manager', 'earlier' => [], 'link' => '/loans/pending', 'label' => 'MANAGER APPROVAL'],
            LoanStatus::PendingCreditReview->value => ['permission' => 'loans.credit_review', 'earlier' => LoanWorkflow::MANAGER_STAGE, 'link' => '/loans/credit-review', 'label' => 'CREDIT REVIEW'],
            LoanStatus::PendingFinance->value => ['permission' => 'loans.prepare_disbursement', 'earlier' => LoanWorkflow::FINANCE_EARLIER_STAGES, 'link' => '/loans/disbursement', 'label' => 'PREPARE DISBURSEMENT'],
            LoanStatus::AwaitingDisbursement->value => ['permission' => 'loans.disburse', 'earlier' => LoanWorkflow::FINANCE_EARLIER_STAGES, 'link' => '/loans/disbursement', 'label' => 'DISBURSE'],
        ];
        $visible = array_filter($stages, fn (array $stage): bool => $this->canAny($stage['permission']));
        if ($visible === []) {
            return null;
        }

        $workflow = app(LoanWorkflow::class);

        return $this->access->scope(Loan::query(), $this->viewer)
            ->whereIn('status', array_keys($visible))
            ->with(['branch', 'customer', 'employee'])
            ->get()
            ->map(function (Loan $loan) use ($visible, $workflow): array {
                $stage = $visible[$loan->status->value];
                $reason = $workflow->segregationBlockedReason($loan, $this->viewer, $stage['earlier']);

                return array_merge($this->row(
                    ApprovalPolicy::LOAN_APPROVALS,
                    $loan->id,
                    $stage['label'].' — '.$loan->loan_number.' '.$loan->customer?->full_name,
                    $loan->branch?->name,
                    (float) ($loan->amount_approved ?: $loan->amount_applied),
                    $loan->employee?->full_name,
                    $loan->created_at,
                    $loan->status->value,
                    $stage['link'],
                    null,
                    true,
                ), ['can_approve' => $reason === null, 'approve_blocked_reason' => $reason]);
            })->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function writeOffs(): ?array
    {
        if (! $this->canAny('loans.write_off')) {
            return null;
        }

        $loans = app(LoanService::class);

        return $loans->pendingWriteOffRequests((int) $this->viewer->company_id, $this->branchIds)['rows']
            ->map(fn (WriteOffRequest $row): array => $this->row(
                ApprovalPolicy::WRITE_OFFS,
                $row->id,
                'WRITE-OFF — '.$row->loan?->loan_number.' '.$row->loan?->customer?->full_name,
                $row->branch?->name,
                $row->loan === null ? 0.0 : (float) $loans->outstanding($row->loan)['total'],
                $row->requester?->full_name,
                $row->created_at,
                'pending',
                "/loans/{$row->loan_id}",
                $row->requested_by,
                true,
            ))->values()->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function reversalRequests(): ?array
    {
        if (! $this->canAny('reversals.approve', 'loans.reverse_repayment', 'loans.reverse_disbursement', 'penalties.reverse_payment')) {
            return null;
        }

        $requests = app(ReversalRequests::class);

        return ReversalRequest::query()
            ->where('company_id', $this->viewer->company_id)
            ->where('status', ReversalRequest::PENDING)
            ->when($this->branchIds !== null, fn (Builder $query) => $query->where(fn (Builder $inner) => $inner->whereIn('branch_id', $this->branchIds)->orWhereNull('branch_id')))
            ->with(['subject', 'loan.customer', 'branch', 'requester'])
            ->get()
            ->map(fn (ReversalRequest $row): array => $this->row(
                ApprovalPolicy::REVERSAL_REQUESTS,
                $row->id,
                'REVERSAL — '.$requests->describe($row).($row->loan?->customer ? ' '.$row->loan->customer->full_name : ''),
                $row->branch?->name,
                (float) $row->amount,
                $row->requester?->full_name,
                $row->created_at,
                'pending',
                '/reversals',
                $row->requested_by,
                $this->canAny('reversals.approve'),
            ))->values()->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function legacyImports(): ?array
    {
        if (! $this->canAny('legacy_imports.approve', 'legacy_imports.manage')) {
            return null;
        }

        $imports = app(LegacyImportService::class);

        return LegacyImport::query()
            ->where('company_id', $this->viewer->company_id)
            ->where('status', LegacyImport::STATUS_PENDING)
            ->when($this->branchIds !== null, fn (Builder $query) => $query->whereIn('branch_id', $this->branchIds))
            ->with(['branch', 'submitter', 'uploader'])
            ->get()
            ->map(fn (LegacyImport $import): array => $this->row(
                self::LEGACY_IMPORTS,
                $import->id,
                sprintf('OLD SYSTEM %s — %s (%d rows to import)', strtoupper($import->moduleLabel()), $import->file_name, (int) ($import->totals['importable_rows'] ?? 0)),
                $import->branch?->name,
                (float) (($import->totals['loan_outstanding'] ?? 0) + ($import->totals['penalty_outstanding'] ?? 0) + ($import->totals['salary_advance_outstanding'] ?? 0)),
                ($import->submitter ?? $import->uploader)?->full_name,
                $import->submitted_at ?? $import->uploaded_at,
                'pending',
                '/imports/'.$import->id,
                array_values(array_filter([$import->uploaded_by, $import->submitted_by])),
                $this->canAny('legacy_imports.approve') && $imports->ownImportReason($import, $this->viewer) === null,
            ))->values()->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function salaryAdvances(): ?array
    {
        if (! $this->canAny('salary_advance.manage')) {
            return null;
        }

        return $this->access->scope(SalaryAdvance::query(), $this->viewer)
            ->where('status', 'pending')
            ->with(['branch', 'customer', 'employee'])
            ->get()
            ->map(fn (SalaryAdvance $row): array => $this->row(
                ApprovalPolicy::SALARY_ADVANCES,
                $row->id,
                'SALARY ADVANCE — '.$row->customer?->full_name,
                $row->branch?->name,
                (float) $row->amount,
                $row->employee?->full_name,
                $row->created_at,
                'pending',
                '/salary-advance/requested',
                $row->employee_id,
                true,
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function staffCredit(): ?array
    {
        $credit = app(StaffCredit::class);
        if (! $this->canAny('hrm.manage', 'payroll.pay', 'payroll.approve') && ! $credit->isAdmin($this->viewer)) {
            return null;
        }

        $labels = ['approve' => 'APPROVE (HR) ', 'admin_approve' => 'APPROVE (ADMIN) ', 'finance_approve' => 'FINANCE APPROVE ', 'disburse' => 'DISBURSE '];
        $present = function (StaffLoan|StaffSalaryAdvance $row, string $label, float $amount, string $link) use ($credit, $labels): array {
            $step = $credit->nextStep($row, $this->viewer);

            return array_merge($this->row(
                ApprovalPolicy::STAFF_CREDIT,
                $row->id,
                ($labels[$step['action']] ?? '').$label.' — '.$row->employee?->full_name,
                $row->branch?->name,
                $amount,
                null,
                $row->created_at,
                $row->status,
                $link,
                null,
                $step['permitted'],
            ), ['can_approve' => $step['permitted'] && $step['blocked_reason'] === null, 'approve_blocked_reason' => $step['blocked_reason']]);
        };

        return [
            ...$this->access->scope(StaffLoan::query(), $this->viewer)->whereIn('status', StaffCreditStatus::awaitingDisbursement())->with(['employee', 'branch'])->get()
                ->map(fn (StaffLoan $row): array => $present($row, 'STAFF LOAN', (float) ($row->amount_approved ?: $row->amount_applied), '/hrm/staff-loans'))->all(),
            ...$this->access->scope(StaffSalaryAdvance::query(), $this->viewer)->whereIn('status', StaffCreditStatus::awaitingDisbursement())->with(['employee', 'branch'])->get()
                ->map(fn (StaffSalaryAdvance $row): array => $present($row, 'STAFF SALARY ADVANCE', (float) $row->amount, '/hrm/salary-advances'))->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function payroll(): ?array
    {
        if (! $this->canAny('payroll.approve', 'payroll.pay')) {
            return null;
        }

        return PayrollRun::where('company_id', $this->viewer->company_id)
            ->whereIn('status', [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_APPROVED])
            ->with('preparer')
            ->get()
            ->map(fn (PayrollRun $row): array => $this->row(
                ApprovalPolicy::PAYROLL,
                $row->id,
                ($row->status === PayrollRun::STATUS_DRAFT ? 'APPROVE' : 'PAY').' PAYROLL '.($row->period instanceof CarbonInterface ? $row->period->format('F Y') : $row->period),
                null,
                (float) $row->total_net,
                $row->preparer?->full_name,
                $row->created_at,
                $row->status,
                '/hrm/salary-sheet',
                $row->prepared_by,
                $this->canAny($row->status === PayrollRun::STATUS_DRAFT ? 'payroll.approve' : 'payroll.pay'),
            ))->all();
    }

    /**
     * Staff allowances HR created and Finance has not approved yet. As on the module page: HR or Finance see them, Finance
     * (`payroll.pay`) approves, and neither the HR creator nor the receiving employee may approve (payroll policy).
     *
     * @return list<array<string, mixed>>|null
     */
    private function allowances(): ?array
    {
        if (! $this->canAny('hrm.manage', 'payroll.pay')) {
            return null;
        }

        $permitted = $this->canAny('payroll.pay');

        return $this->access->scope(StaffAllowance::query(), $this->viewer)
            ->where('status', StaffAllowance::STATUS_PENDING)
            ->with(['branch', 'employee', 'creator'])
            ->get()
            ->map(fn (StaffAllowance $row): array => array_merge($this->row(
                self::ALLOWANCES,
                $row->id,
                'ALLOWANCE ('.strtoupper((string) $row->reason).') '.$row->payroll_period?->format('F Y').' — '.$row->employee?->full_name,
                $row->branch?->name,
                (float) $row->amount,
                $row->creator?->full_name,
                $row->created_at,
                $row->status,
                '/hrm/allowances',
                [$row->created_by, $row->employee_id],
                $permitted,
            ), $this->duties->flags([$row->created_by, $row->employee_id], $this->viewer, true, $permitted, workflow: ApprovalPolicy::PAYROLL)))->all();
    }

    /**
     * Negligence / loss deductions HR created and Finance has not approved yet (recovered later from commission only). Same
     * visibility, permission and segregation rule as the module page.
     *
     * @return list<array<string, mixed>>|null
     */
    private function negligenceDeductions(): ?array
    {
        if (! $this->canAny('hrm.manage', 'payroll.pay')) {
            return null;
        }

        $permitted = $this->canAny('payroll.pay');

        return $this->access->scope(NegligenceDeduction::query(), $this->viewer)
            ->where('status', NegligenceDeduction::STATUS_PENDING)
            ->with(['branch', 'employee', 'creator'])
            ->get()
            ->map(fn (NegligenceDeduction $row): array => array_merge($this->row(
                self::NEGLIGENCE_DEDUCTIONS,
                $row->id,
                'NEGLIGENCE / LOSS — '.$row->employee?->full_name.': '.Str::limit((string) $row->reason, 80),
                $row->branch?->name,
                (float) $row->amount,
                $row->creator?->full_name,
                $row->created_at,
                $row->status,
                '/hrm/negligence-deductions',
                [$row->created_by, $row->employee_id],
                $permitted,
            ), $this->duties->flags([$row->created_by, $row->employee_id], $this->viewer, true, $permitted, workflow: ApprovalPolicy::PAYROLL)))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function tellerDeposits(): ?array
    {
        if (! $this->canAny('payments.verify')) {
            return null;
        }

        return $this->access->scope(TellerDeposit::query(), $this->viewer)
            ->whereIn('status', [TellerDeposit::STATUS_PENDING, TellerDeposit::STATUS_MISMATCH, TellerDeposit::STATUS_VERIFIED])
            ->with(['branch', 'employee'])
            ->get()
            ->map(fn (TellerDeposit $row): array => $this->row(
                ApprovalPolicy::TELLER_DEPOSITS,
                $row->id,
                ($row->status === TellerDeposit::STATUS_VERIFIED ? 'CONFIRM' : 'VERIFY').' DEPOSIT SLIP '.$row->slip_number,
                $row->branch?->name,
                (float) $row->amount,
                $row->employee?->full_name,
                $row->created_at,
                $row->status,
                '/payments/reconciliation',
                $row->employee_id,
                true,
            ))->all();
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function branchReceipts(): ?array
    {
        if (! $this->canAny('payments.verify')) {
            return null;
        }

        return app(PaymentService::class)->pendingBranchReceipts((int) $this->viewer->company_id, $this->branchIds)['rows']
            ->map(fn (Payment $row): array => $this->row(
                ApprovalPolicy::BRANCH_RECEIPTS,
                $row->id,
                strtoupper((string) $row->channel).' '.($row->transaction_id ?? '').' — '.$row->loan?->loan_number.' '.$row->customer?->full_name,
                $row->branch?->name,
                (float) $row->amount,
                $row->employee?->full_name,
                $row->created_at,
                'pending_approval',
                '/payments/branch-receipts',
                $row->employee_id,
                true,
            ))->values()->all();
    }

    /**
     * One list row with the viewer's approval flags ({@see SegregationOfDuties::flags()} with the workflow's policy key).
     *
     * @param  int|list<int|null>|null  $initiatorIds
     * @return array<string, mixed>
     */
    private function row(
        string $workflow,
        int $id,
        string $description,
        ?string $branch,
        float $amount,
        ?string $requestedBy,
        ?CarbonInterface $requestedAt,
        string $status,
        string $link,
        int|array|null $initiatorIds,
        bool $permitted,
    ): array {
        return [
            'workflow' => $workflow,
            'workflow_label' => self::GROUPS[$workflow],
            'id' => $id,
            'description' => $description,
            'branch' => $branch,
            'amount' => round($amount, 2),
            'requested_by' => $requestedBy,
            'requested_at' => $requestedAt?->toDateTimeString(),
            'status' => $status,
            'link' => $link,
            ...$this->duties->flags($initiatorIds, $this->viewer, true, $permitted, workflow: $workflow),
        ];
    }

    /**
     * An HQ transaction names an HQ Account List row on the FROM side and a shareholders' account on the TO side; rows
     * raised before that change name a ledger account on both sides.
     */
    private function hqAccountName(string $account): string
    {
        return HqFund::tryFrom($account)?->label()
            ?? ShareholderAccounts::nameOf($account)
            ?? Account::tryFrom($account)?->label()
            ?? $account;
    }

    private function canAny(string ...$permissions): bool
    {
        return array_intersect($permissions, $this->permissions) !== [];
    }
}
