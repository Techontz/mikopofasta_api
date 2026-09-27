<?php

namespace App\Services\Reports;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The "branch + from/to" filter shared by the report pages.
 * A null branch means every branch of the company (the live "ALL" option).
 */
final readonly class ReportFilter
{
    public function __construct(
        public Company $company,
        public ?int $branchId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $dated = true,
    ) {}

    /**
     * Inclusive bounds for date columns. The upper bound carries the end of day because date-cast
     * columns are stored as "Y-m-d H:i:s" strings.
     *
     * @return array{0: string, 1: string}
     */
    public function range(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString().' 23:59:59'];
    }

    /**
     * Build the filter from the query string. Unknown branch ids (other companies) abort with 404.
     */
    public static function fromRequest(Request $request, Company $company, bool $defaultToToday = true): self
    {
        $branch = $request->query('blanch_id');
        $branchId = null;

        if (is_string($branch) && ctype_digit($branch)) {
            abort_unless($company->branches()->whereKey((int) $branch)->exists(), 404);
            $branchId = (int) $branch;
        }

        $today = CarbonImmutable::today();
        $hasDates = $request->filled('from') || $request->filled('to');
        $from = $request->date('from')?->toImmutable() ?? $today;
        $to = $request->date('to')?->toImmutable() ?? $from;

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        return new self($company, $branchId, $from->startOfDay(), $to->startOfDay(), $defaultToToday || $hasDates);
    }
}
