<?php

namespace App\Http\Resources\Api\V1\Hrm;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\SalaryPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Employee Portal (spec §60) payslip row of the signed-in employee: a paid salary payment, or their line on a Finance-approved
 * payroll that is not paid yet. Only the employee's own figures — never the paying account or payroll totals.
 *
 * @property SalaryPayment|PayrollItem $resource
 */
class MyPayslipResource extends JsonResource
{
    public const STATUS_PAID = 'paid';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->resource;
        $paid = $row instanceof SalaryPayment;
        /** @var PayrollRun|null $run */
        $run = $paid ? $row->payrollRun : $row->run;
        $period = $run?->period ?? ($paid ? $row->paid_on : null);

        $basic = (float) ($paid ? $row->salary : $row->base_salary);
        $commission = (float) $row->commission;
        $allowance = (float) $row->allowance;
        $staffFund = (float) $row->staff_fund;
        $advance = (float) $row->salary_advance;
        $loan = (float) $row->loan_restoration;
        $other = (float) $row->deduction;
        $negligence = (float) $row->negligence;

        return [
            'payslip_id' => $paid ? $row->id : null,
            'payroll_period' => $period?->format('Y-m'),
            'period_label' => $period?->format('F Y'),
            'basic_salary' => $basic,
            'allowance' => $allowance,
            'commission' => $commission,
            'gross' => round($basic + $commission + $allowance, 2),
            'staff_fund' => $staffFund,
            'salary_advance' => $advance,
            'loan_restoration' => $loan,
            'other_deductions' => $other,
            'negligence' => $negligence,
            'total_deductions' => round($staffFund + $advance + $loan + $other + $negligence, 2),
            'net_salary' => (float) $row->take_home,
            'payment_status' => $paid ? self::STATUS_PAID : self::STATUS_AWAITING_PAYMENT,
            'payment_status_label' => $paid ? 'Paid' : 'Approved / Awaiting Payment',
            'paid_on' => $paid ? $row->paid_on?->toDateString() : null,
        ];
    }
}
