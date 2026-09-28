<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One file of old-system data uploaded for one branch: a Loan File, a Penalty List or an Active Salary Advance list.
 *
 * Its rows are staged and validated on upload and change no balance. Only when someone other than the uploader
 * approves it do the rows become loans, penalties and salary advances, and one balanced opening journal entry is
 * posted for the receivables they bring in.
 */
class LegacyImport extends Model
{
    public const MODULE_LOAN = 'loan';

    public const MODULE_PENALTY = 'penalty';

    public const MODULE_SALARY_ADVANCE = 'salary_advance';

    public const MODULES = [self::MODULE_LOAN, self::MODULE_PENALTY, self::MODULE_SALARY_ADVANCE];

    /** Uploaded and validated; the uploader may still replace the file. */
    public const STATUS_DRAFT = 'draft';

    /** Sent for approval; waiting for someone other than the uploader. */
    public const STATUS_PENDING = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** Approved, then undone as a whole (safe recovery): its records deleted and its opening journal reversed. */
    public const STATUS_ROLLED_BACK = 'rolled_back';

    /** Statuses whose rows count as already in the system when another file is checked for duplicates. */
    public const HOLDING_STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Foreign keys as integers: some MySQL drivers return them as strings, and the code compares ids strictly.
            'company_id' => 'integer',
            'branch_id' => 'integer',
            'uploaded_by' => 'integer',
            'submitted_by' => 'integer',
            'approved_by' => 'integer',
            'rejected_by' => 'integer',
            'rolled_back_by' => 'integer',
            'year' => 'integer',
            'totals' => 'array',
            'uploaded_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'rolled_back_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<LegacyImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(LegacyImportRow::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function submitter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'submitted_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rejected_by');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function rollbacker(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'rolled_back_by');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * Rows that will become records when this import is approved: everything that validated, matched a customer and is
     * not already in the system.
     *
     * @return HasMany<LegacyImportRow, $this>
     */
    public function importableRows(): HasMany
    {
        return $this->rows()->whereIn('status', LegacyImportRow::IMPORTABLE)->whereNotNull('customer_id');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_REJECTED], true);
    }

    /**
     * The module as it is named on screen.
     */
    public function moduleLabel(): string
    {
        return match ($this->module) {
            self::MODULE_LOAN => 'Loan File',
            self::MODULE_PENALTY => 'Penalty List',
            self::MODULE_SALARY_ADVANCE => 'Active Salary Advance',
            default => $this->module,
        };
    }
}
