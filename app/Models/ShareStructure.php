<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A company's share structure: the initial capital basis divided into the initial number of shares (the initial share
 * value) and an optional authorised share limit. Share quantities live in the share register, never percentages.
 */
class ShareStructure extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'authorised_shares' => 'integer',
            'initial_capital_basis' => 'decimal:2',
            'initial_shares' => 'integer',
            'initial_share_value' => 'decimal:2',
            'established_on' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
