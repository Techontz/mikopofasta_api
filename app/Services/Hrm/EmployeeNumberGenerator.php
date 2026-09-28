<?php

namespace App\Services\Hrm;

use App\Models\Employee;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Empl/ID generator for the live pattern MK-<3 digit sequence><year> (e.g. MK-0582026).
 *
 * Numbers are unique per company (unique index employees.company_id + employee_number). The next sequence is
 * one above the highest ever issued: the per-company counter row (employee_number_sequences), which is never
 * lowered, so deleting, rejecting or blocking staff never frees a number — or the highest sequence found on
 * existing staff when that is higher (numbers written outside the generator). The counter row is locked FOR
 * UPDATE until the surrounding transaction commits, so concurrent registrations queue instead of colliding;
 * a number that already exists (same sequence and year) is skipped.
 *
 * The demo login accounts (config demo.accounts, MK-901…908) are a reserved block and do not move the sequence.
 */
class EmployeeNumberGenerator
{
    public const PREFIX = 'MK-';

    /**
     * Reserve the next number. Call inside the transaction that creates the employee.
     */
    public function next(int $companyId, ?CarbonInterface $at = null): string
    {
        return DB::transaction(function () use ($companyId, $at): string {
            DB::table('employee_number_sequences')->insertOrIgnore(['company_id' => $companyId, 'last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);

            $counter = DB::table('employee_number_sequences')->where('company_id', $companyId)->lockForUpdate()->first();
            $year = ($at ?? now())->format('Y');
            $sequence = max((int) $counter->last_sequence, $this->highestSequence($companyId)) + 1;

            while (Employee::where('company_id', $companyId)->where('employee_number', $this->format($sequence, $year))->exists()) {
                $sequence++;
            }

            DB::table('employee_number_sequences')->where('company_id', $companyId)->update(['last_sequence' => $sequence, 'updated_at' => now()]);

            return $this->format($sequence, $year);
        });
    }

    public function format(int $sequence, string $year): string
    {
        return self::PREFIX.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT).$year;
    }

    /**
     * Sequence part of an Empl/ID ("MK-0582026" → 58), or null when it does not follow the pattern.
     */
    public function sequenceOf(?string $employeeNumber): ?int
    {
        return $employeeNumber !== null && preg_match('/^MK-(\d{3,})(\d{4})$/', $employeeNumber, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * Highest sequence on the company's staff of every status, ignoring the reserved demo login numbers.
     */
    public function highestSequence(int $companyId): int
    {
        $reserved = array_values(array_filter(array_column((array) config('demo.accounts', []), 'employee_number')));

        return (int) Employee::where('company_id', $companyId)
            ->where('employee_number', 'like', self::PREFIX.'%')
            ->when($reserved !== [], fn ($query) => $query->whereNotIn('employee_number', $reserved))
            ->pluck('employee_number')
            ->map(fn (string $number): int => $this->sequenceOf($number) ?? 0)
            ->max();
    }
}
