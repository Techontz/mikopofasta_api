<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\Enums\JournalSourceType;
use App\Exceptions\ImmutableRecordException;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Backend spec §2.7 — `journal_entries`.
 *
 * IMMUTABLE. §8: "journal_entries/journal_entry_lines models override
 * delete()/update() (Eloquent) to throw, so even a raw Artisan tinker session
 * can't quietly violate 'no delete, only reversal'."
 *
 * That is not belt-and-braces around the missing `deleted_at` column — it is
 * the same rule stated twice, because the schema can only stop a DELETE and
 * says nothing about an UPDATE that quietly rewrites an amount.
 *
 * @property int $id
 * @property string $entry_number
 * @property CarbonImmutable $entry_date
 * @property string $description
 * @property JournalSourceType $source_type
 * @property int|null $source_id
 * @property bool $is_reversal
 * @property int|null $reversed_entry_id
 * @property int $created_by
 * @property CarbonImmutable $posted_at
 */
class JournalEntry extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'entry_number', 'entry_date', 'description', 'source_type', 'source_id',
        'is_reversal', 'reversed_entry_id', 'created_by', 'posted_at', 'created_at',
    ];

    /**
     * @return HasMany<JournalEntryLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The original this entry reverses, if it is a reversal.
     *
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversed_entry_id');
    }

    /**
     * The reversal that undid this entry, if one exists.
     *
     * @return HasMany<JournalEntry, $this>
     */
    public function reversals(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'reversed_entry_id');
    }

    public function totalDebits(): Money
    {
        return Money::sum($this->lines->map(fn (JournalEntryLine $l): Money => $l->debitAmount()));
    }

    public function totalCredits(): Money
    {
        return Money::sum($this->lines->map(fn (JournalEntryLine $l): Money => $l->creditAmount()));
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits()->equals($this->totalCredits());
    }

    public function hasBeenReversed(): bool
    {
        return $this->reversals()->exists();
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw ImmutableRecordException::cannotUpdate(self::class);
    }

    public function delete(): bool
    {
        throw ImmutableRecordException::cannotDelete(self::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_type' => JournalSourceType::class,
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'created_at' => 'datetime',
            'is_reversal' => 'boolean',
        ];
    }
}
