<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Company-wide HRM parameters (STAFF COMMISSION §7 "Commission Pool = % × Distributable Profit",
 * §8 zone manager override %, §12 staff fund "% ya salary", spec §26 company staff fund contribution %; attendance start time).
 */
class HrmSetting extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_pool_percent' => 'decimal:2',
            'zone_override_percent' => 'decimal:2',
            'staff_fund_percent' => 'decimal:2',
        ];
    }

    /**
     * The company's settings row, created on first use. A new row is re-read so the column defaults (10 % pool, 5 % zone,
     * 20 % staff and 20 % company fund contribution) apply immediately instead of reading as empty.
     */
    public static function forCompany(int $companyId): self
    {
        $settings = self::firstOrCreate(['company_id' => $companyId]);

        return $settings->wasRecentlyCreated ? $settings->refresh() : $settings;
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
