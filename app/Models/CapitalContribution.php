<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Treasury\Enums\PayMethod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Money a shareholder put into the company — Capital → Add Capitals.
 *
 * Every row has a posted journal entry behind it: the contribution and its
 * ledger effect are created in one transaction.
 *
 * @property int $id
 * @property int $shareholder_id
 * @property string $amount
 * @property PayMethod $pay_method
 * @property string|null $receipt_no
 * @property string|null $cheque_no
 * @property int|null $journal_entry_id
 * @property CarbonImmutable|null $created_at
 */
class CapitalContribution extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'shareholder_id', 'amount', 'pay_method', 'receipt_no', 'cheque_no', 'journal_entry_id', 'created_by',
    ];

    /** @return BelongsTo<Shareholder, $this> */
    public function shareholder(): BelongsTo
    {
        return $this->belongsTo(Shareholder::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['pay_method' => PayMethod::class, 'amount' => 'decimal:2'];
    }
}
