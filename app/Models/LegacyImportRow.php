<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One row of an uploaded legacy file, kept exactly as it arrived (`raw`) beside the values read from it, the customer
 * it matched and how, whatever was wrong with it, and — once the import is approved — the record it became.
 */
class LegacyImportRow extends Model
{
    /** Ready to import. */
    public const STATUS_VALID = 'valid';

    /** Importable, but something is worth a second look (e.g. the name matched on name and branch alone). */
    public const STATUS_WARNING = 'warning';

    /** Cannot be imported: a missing name, an unknown branch, an impossible amount. */
    public const STATUS_ERROR = 'error';

    /** The same record is already in the system, from this file or an earlier import. */
    public const STATUS_DUPLICATE = 'duplicate';

    /** No customer could be identified with confidence; an Admin must map it. */
    public const STATUS_UNMATCHED = 'unmatched';

    /** Imported, with `imported` pointing at the record it became. */
    public const STATUS_IMPORTED = 'imported';

    /** Imported, then undone when its import was rolled back. */
    public const STATUS_ROLLED_BACK = 'rolled_back';

    /** Statuses that become records on approval. */
    public const IMPORTABLE = [self::STATUS_VALID, self::STATUS_WARNING];

    public const MATCH_CUSTOMER_ID = 'customer_id';

    public const MATCH_PHONE = 'phone';

    public const MATCH_NAME_BRANCH = 'name_branch';

    public const MATCH_MANUAL = 'manual';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Foreign keys as integers: some MySQL drivers return them as strings, and the code compares ids strictly.
            'legacy_import_id' => 'integer',
            'customer_id' => 'integer',
            'mapped_by' => 'integer',
            'row_number' => 'integer',
            'raw' => 'array',
            'messages' => 'array',
            'monthly' => 'array',
            'sessions' => 'integer',
            'loan_amount' => 'decimal:2',
            'interest' => 'decimal:2',
            'total_payable' => 'decimal:2',
            'collection' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remain_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'withdrawal_date' => 'date',
            'penalty_date' => 'date',
            'alert_date' => 'date',
            'fee' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<LegacyImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(LegacyImport::class, 'legacy_import_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function mapper(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'mapped_by');
    }

    /**
     * The loan, penalty or salary advance this row became.
     *
     * @return MorphTo<Model, $this>
     */
    public function imported(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Add a message for the preview and the exception file, keeping the worst status the row has been given.
     *
     * @param  self::STATUS_*  $status
     */
    public function note(string $status, string $message): void
    {
        $this->messages = [...($this->messages ?? []), $message];
        $this->status = self::worst($this->status, $status);
    }

    /**
     * Of two statuses, the one that keeps the row further from being imported.
     */
    public static function worst(string $a, string $b): string
    {
        $order = [self::STATUS_VALID => 0, self::STATUS_WARNING => 1, self::STATUS_DUPLICATE => 2, self::STATUS_UNMATCHED => 3, self::STATUS_ERROR => 4];

        return ($order[$a] ?? 0) >= ($order[$b] ?? 0) ? $a : $b;
    }
}
