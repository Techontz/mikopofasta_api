<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Allowance;
use App\Models\Deduction;
use App\Models\PayrollLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `PayrollLineSchema` in the frontend's types/payroll.ts, with the
 * allowance and deduction items alongside — a payslip is not legible without
 * the itemisation behind its totals.
 *
 * @mixin PayrollLine
 */
final class PayrollLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'payrollRunId' => (string) $this->payroll_run_id,
            'staffProfileId' => (string) $this->staff_profile_id,
            'baseSalary' => $this->base_salary,
            'commissionAmount' => $this->commission_amount,
            'allowancesTotal' => $this->allowances_total,
            'deductionsTotal' => $this->deductions_total,
            'netSalary' => $this->net_salary,

            // Null while the run is a draft — nothing has been posted yet.
            'journalEntryId' => $this->journal_entry_id === null ? null : (string) $this->journal_entry_id,

            'staffName' => $this->whenLoaded('staffProfile', fn (): string => $this->staffProfile->displayName()),

            'allowances' => $this->whenLoaded('allowances', fn (): array => $this->allowances
                ->map(fn (Allowance $a): array => [
                    'id' => (string) $a->id,
                    'payrollLineId' => (string) $a->payroll_line_id,
                    'type' => $a->type->value,
                    'amount' => $a->amount,
                ])->all()),

            'deductions' => $this->whenLoaded('deductions', fn (): array => $this->deductions
                ->map(fn (Deduction $d): array => [
                    'id' => (string) $d->id,
                    'payrollLineId' => (string) $d->payroll_line_id,
                    'type' => $d->type->value,
                    'amount' => $d->amount,
                    'referenceId' => $d->reference_id === null ? null : (string) $d->reference_id,
                ])->all()),
        ];
    }
}
