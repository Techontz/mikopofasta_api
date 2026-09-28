<?php

namespace App\Models;

use App\Services\Approvals\SegregationOfDuties;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Company approval policy for one two-step (maker/checker) workflow (C6). A missing row means the defaults: approval required,
 * self-approval not allowed. The initiator may approve their own item only when BOTH this policy allows self-approval AND the
 * employee explicitly holds `approvals.self_approve` ({@see SegregationOfDuties}).
 *
 * `requires_approval` is stored for future use and ignored: every approved financial flow stays two-step.
 */
class ApprovalPolicy extends Model
{
    public const EXPENSES = 'expenses.request';

    public const FLOATS = 'floats.transfer';

    public const BANK_TRANSFERS = 'bank.transfer';

    public const HQ_TRANSACTIONS = 'hq.transaction';

    public const CAPITAL_CONTRIBUTIONS = 'capital.contribution';

    public const ASSET_CONTRIBUTIONS = 'assets.contribution';

    public const SHARE_ISSUANCES = 'shares.issuance';

    public const DIVIDEND_DECLARATIONS = 'dividends.declaration';

    public const LOAN_APPROVALS = 'loans.approval';

    public const WRITE_OFFS = 'loans.write_off';

    public const SALARY_ADVANCES = 'salary_advance.approval';

    public const STAFF_CREDIT = 'hrm.staff_credit';

    public const PAYROLL = 'payroll.run';

    public const TELLER_DEPOSITS = 'payments.teller_deposit';

    public const BRANCH_RECEIPTS = 'payments.branch_receipt';

    public const REVERSALS = 'accounting.reversal';

    public const REVERSAL_REQUESTS = 'reversals.request';

    /**
     * Workflow key => label shown in Settings → Approval Policy.
     *
     * @var array<string, string>
     */
    public const WORKFLOWS = [
        self::EXPENSES => 'Expenses (branch, HQ and bank expenses)',
        self::FLOATS => 'Floats (company → branch, branch → branch, account → account)',
        self::BANK_TRANSFERS => 'Bank transfers (branch → bank, bank → branch / HQ, company cash ↔ bank)',
        self::HQ_TRANSACTIONS => 'HQ transactions',
        self::CAPITAL_CONTRIBUTIONS => 'Capital contributions (cash / bank)',
        self::ASSET_CONTRIBUTIONS => 'Asset capital contributions',
        self::SHARE_ISSUANCES => 'Paid share issuances',
        self::DIVIDEND_DECLARATIONS => 'Dividend declarations',
        self::LOAN_APPROVALS => 'Loan approval chain (manager, credit, finance / disbursement)',
        self::WRITE_OFFS => 'Loan write-offs',
        self::SALARY_ADVANCES => 'Customer salary advances',
        self::STAFF_CREDIT => 'Staff loans and staff salary advances',
        self::PAYROLL => 'Payroll (approve and pay)',
        self::TELLER_DEPOSITS => 'Teller cash and bank deposit verification / confirmation',
        self::BRANCH_RECEIPTS => 'Branch non-cash receipts (mobile money / bank)',
        self::REVERSALS => 'Reversals (the poster reversing their own transaction)',
        self::REVERSAL_REQUESTS => 'Reversal requests (loan repayment, loan disbursement, penalty payment)',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'allow_self_approval' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'updated_by');
    }
}
