<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AccountingPeriod;
use App\Models\PeriodBranchResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One closed period, with the per-branch breakdown behind it.
 *
 * The reserve percentage is emitted alongside the amount deliberately: a period
 * closed at one rate and read after the rate changed would otherwise look as if
 * the arithmetic were wrong.
 *
 * @mixin AccountingPeriod
 */
final class AccountingPeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'period' => $this->period,
            'status' => $this->status->value,
            'incomeTotal' => $this->income_total,
            'expenseTotal' => $this->expense_total,
            'realisedProfit' => $this->realised_profit,
            'reservePercentage' => $this->reserve_percentage,
            'reserveAppropriated' => $this->reserve_appropriated,
            'profitJournalEntryId' => $this->profit_journal_entry_id === null
                ? null
                : (string) $this->profit_journal_entry_id,
            'reserveJournalEntryId' => $this->reserve_journal_entry_id === null
                ? null
                : (string) $this->reserve_journal_entry_id,
            'closedAt' => $this->closed_at?->toIso8601String(),
            'closedByName' => $this->whenLoaded('closer', fn (): ?string => $this->closer?->name),
            'notes' => $this->notes,

            'branchResults' => $this->whenLoaded(
                'branchResults',
                fn (): array => $this->branchResults
                    ->map(fn (PeriodBranchResult $r): array => [
                        'branchId' => (string) $r->branch_id,
                        'branchName' => $r->branch?->name,
                        'incomeTotal' => $r->income_total,
                        'expenseTotal' => $r->expense_total,
                        'realisedProfit' => $r->realised_profit,
                        'reserveAppropriated' => $r->reserve_appropriated,
                    ])
                    ->all(),
            ),
        ];
    }
}
