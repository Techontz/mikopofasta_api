<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShareHolder extends Model
{
    use Auditable;

    /**
     * Private disk holding passport photos (served only through the authorised API).
     */
    public const DISK = 'local';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
        ];
    }

    /**
     * `name` is the legacy single full-name column; it is kept in sync with the three name parts so older
     * records and any consumer of the column still read a complete name.
     */
    protected static function booted(): void
    {
        static::saving(function (ShareHolder $holder): void {
            if ($holder->first_name !== null) {
                $holder->name = $holder->full_name;
            }
        });
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(function (): string {
            $parts = array_filter([$this->first_name, $this->middle_name, $this->last_name], fn (?string $part): bool => filled($part));

            return $parts === [] ? (string) $this->name : implode(' ', $parts);
        });
    }

    /**
     * The login account (employees row) of this shareholder — 1:1, ON DELETE RESTRICT.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    /**
     * "SH-0007": the shareholder number shown in the portal directory (the register has no separate holder number).
     */
    protected function holderNumber(): Attribute
    {
        return Attribute::get(fn (): string => 'SH-'.str_pad((string) $this->id, 4, '0', STR_PAD_LEFT));
    }

    public function capitals(): HasMany
    {
        return $this->hasMany(Capital::class);
    }

    public function dividendAllocations(): HasMany
    {
        return $this->hasMany(DividendAllocation::class);
    }

    /**
     * Current share holding (share register).
     */
    public function sharePosition(): HasOne
    {
        return $this->hasOne(SharePosition::class);
    }

    public function sharesReceived(): HasMany
    {
        return $this->hasMany(ShareTransaction::class, 'to_share_holder_id');
    }

    public function sharesGivenUp(): HasMany
    {
        return $this->hasMany(ShareTransaction::class, 'from_share_holder_id');
    }
}
