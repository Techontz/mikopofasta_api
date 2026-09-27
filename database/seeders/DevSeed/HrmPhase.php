<?php

namespace Database\Seeders\DevSeed;

use App\Enums\Account;
use App\Enums\StaffCreditStatus;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Leave;
use App\Models\StaffAllowance;
use App\Models\StaffDeduction;
use App\Models\StaffFundWithdrawal;
use App\Models\StaffLoan;
use App\Models\StaffLoanCategory;
use App\Models\StaffPerformanceReview;
use App\Models\StaffSalaryAdvance;
use App\Models\StaffSalaryAdvanceCategory;
use Carbon\CarbonImmutable;

/**
 * HRM records for the DEV staff: leave, allowances, deductions, staff salary advances, staff loans, a staff fund withdrawal,
 * performance reviews and attendance — through the HRM endpoints as HR and Finance.
 */
final class HrmPhase
{
    public function __construct(private readonly Context $ctx) {}

    public function register(): void
    {
        $this->leaves();
        $this->allowancesAndDeductions();
        $this->salaryAdvances();
        $this->staffLoans();
        $this->reviews();
        $this->attendance();
    }

    private function hr(): Employee
    {
        return $this->ctx->staff('HQ_HR');
    }

    private function leaves(): void
    {
        $leaves = [
            ['KK_LO', '2026-05-04 09:00', '2026-05-11', '2026-05-13', 'Kuhudhuria mazishi ya ndugu Kasulu', 'approved'],
            ['KB_LO', '2026-06-01 09:00', '2026-06-15', '2026-06-19', 'Likizo ya mwaka (sehemu ya kwanza)', 'approved'],
            ['KS_TL', '2026-06-25 09:00', '2026-07-01', '2026-07-03', 'Kushughulikia mambo ya kifamilia', 'rejected'],
            ['KM_BM', '2026-07-30 09:00', '2026-08-10', '2026-08-14', 'Likizo ya mwaka', 'approved'],
            ['BH_LO', '2026-09-02 09:00', '2026-09-07', '2026-09-08', 'Matibabu - Hospitali ya Rufaa Maweni', 'approved'],
            ['UV_BM', '2026-09-10 09:00', '2026-09-21', '2026-09-25', 'Likizo ya mwaka', 'pending'],
            ['HQ_FIN2', '2026-09-12 09:00', '2026-09-28', '2026-10-02', 'Mahafali ya shahada ya uzamili', 'pending'],
        ];

        foreach ($leaves as [$staff, $requested, $start, $end, $remarks, $decision]) {
            $leave = fn (): ?Leave => Leave::where('employee_id', $this->ctx->staff($staff)->id)->whereDate('start_date', $start)->first();

            $this->ctx->timeline->at($requested, "leave request {$staff} {$start}", function () use ($staff, $start, $end, $remarks): void {
                $this->ctx->api->call($this->hr(), 'POST', 'hrm/leaves', ['empl_id' => $this->ctx->staff($staff)->id, 'stat_date' => $start, 'end_date' => $end, 'remaks' => $remarks]);
            }, fn (): bool => $leave() !== null);

            if ($decision !== 'pending') {
                $this->ctx->timeline->at(CarbonImmutable::parse($requested)->addDay()->setTime(10, 0), "leave {$decision} {$staff} {$start}", function () use ($leave, $decision): void {
                    $this->ctx->api->call($this->hr(), 'POST', "hrm/leaves/{$leave()->id}/decide", ['status' => $decision]);
                }, fn (): bool => $leave()?->status !== 'pending');
            }
        }
    }

    private function allowancesAndDeductions(): void
    {
        foreach ([['KB_BM', 80000, 'Posho ya usafiri wa kutembelea wateja'], ['KM_BM', 80000, 'Posho ya usafiri wa kutembelea wateja'], ['ZM_ZIWA', 150000, 'Posho ya mawasiliano na usafiri wa kanda'], ['UV_BM', 60000, 'Posho ya nyumba (miezi mitatu)']] as [$staff, $amount, $description]) {
            $allowance = fn (): ?StaffAllowance => StaffAllowance::where('employee_id', $this->ctx->staff($staff)->id)->where('description', $description)->first();

            $this->ctx->timeline->at('2026-05-25 10:00', "allowance {$staff}", function () use ($staff, $amount, $description): void {
                $employee = $this->ctx->staff($staff);
                $this->ctx->api->call($this->hr(), 'POST', 'hrm/allowances', ['blanch_id' => $employee->branch_id, 'empl_id' => $employee->id, 'new_amount' => $amount, 'remaks_allow' => $description]);
            }, fn (): bool => $allowance() !== null);

            if ($staff === 'UV_BM') {
                $this->ctx->timeline->at('2026-08-01 09:00', "allowance stopped {$staff}", function () use ($allowance): void {
                    $this->ctx->api->call($this->hr(), 'POST', "hrm/allowances/{$allowance()->id}/stop");
                }, fn (): bool => $allowance()?->status === 'stopped');
            }
        }

        foreach ([['KB_LO2', 120000, 3, 'Kurejesha simu ya kazi iliyopotea'], ['KS_TL', 50000, 2, 'Upungufu wa fedha kwenye droo ya teller (cash shortage) 28 Mei']] as [$staff, $amount, $instalments, $description]) {
            $this->ctx->timeline->at('2026-06-05 10:00', "deduction {$staff}", function () use ($staff, $amount, $instalments, $description): void {
                $employee = $this->ctx->staff($staff);
                $this->ctx->api->call($this->hr(), 'POST', 'hrm/deductions', ['blanch_id' => $employee->branch_id, 'empl_id' => $employee->id, 'amount' => $amount, 'instalment' => $instalments, 'description' => $description]);
            }, fn (): bool => StaffDeduction::where('employee_id', $this->ctx->staff($staff)->id)->where('description', $description)->exists());
        }
    }

    private function salaryAdvances(): void
    {
        $advances = [
            ['BH_TL', 80000, '2026-08-10 09:00', ['approve' => '2026-08-11 10:00', 'finance' => '2026-08-12 10:00', 'disburse' => '2026-08-12 11:00']],
            ['UV_TL', 100000, '2026-07-20 09:00', ['reject' => '2026-07-21 10:00']],
            ['KB_TL', 60000, '2026-08-05 09:00', ['approve' => '2026-08-05 15:00', 'finance' => '2026-08-06 10:00', 'disburse' => '2026-08-06 11:00']],
            ['KM_LO2', 50000, '2026-09-11 09:00', []],
        ];

        foreach ($advances as [$staff, $amount, $requested, $steps]) {
            $advance = fn (): ?StaffSalaryAdvance => StaffSalaryAdvance::where('employee_id', $this->ctx->staff($staff)->id)->where('amount', $amount)->first();

            $this->ctx->timeline->at($requested, "staff salary advance {$staff}", function () use ($staff, $amount): void {
                $employee = $this->ctx->staff($staff);
                $category = StaffSalaryAdvanceCategory::where('company_id', $this->ctx->company->id)->orderBy('id')->firstOrFail();
                $this->ctx->api->call($this->hr(), 'POST', 'hrm/salary-advances', ['blanch_id' => $employee->branch_id, 'empl_id' => $employee->id, 'fee' => $category->id, 'advance_amount' => $amount]);
            }, fn (): bool => $advance() !== null);

            if (isset($steps['reject'])) {
                $this->ctx->timeline->at($steps['reject'], "staff salary advance rejected {$staff}", fn () => $this->ctx->api->call($this->hr(), 'POST', "hrm/salary-advances/{$advance()->id}/reject"), fn (): bool => $advance()?->status !== StaffCreditStatus::Submitted->value);
            }
            if (isset($steps['approve'])) {
                $this->ctx->timeline->at($steps['approve'], "staff salary advance approved {$staff}", fn () => $this->ctx->api->call($this->hr(), 'POST', "hrm/salary-advances/{$advance()->id}/approve"), fn (): bool => $advance()?->status !== StaffCreditStatus::Submitted->value);
            }
            if (isset($steps['finance'])) {
                $this->ctx->timeline->at($steps['finance'], "staff salary advance finance approved {$staff}", fn () => $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/salary-advances/{$advance()->id}/finance-approve"), fn (): bool => ! in_array($advance()?->status, [StaffCreditStatus::Submitted->value, ...StaffCreditStatus::reviewed()], true));
            }
            if (isset($steps['disburse'])) {
                $this->ctx->timeline->at($steps['disburse'], "staff salary advance disbursed {$staff}", fn () => $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/salary-advances/{$advance()->id}/disburse", ['ac_id' => Account::StaffFundCash->value]), fn (): bool => ! in_array($advance()?->status, StaffCreditStatus::awaitingDisbursement(), true));
            }
        }
    }

    private function staffLoans(): void
    {
        $loans = [
            ['KM_LO', 600000, 4, 'SL1', '2026-07-06 09:00', ['approve' => '2026-07-07 10:00', 'finance' => '2026-07-08 10:00', 'disburse' => '2026-07-08 11:00', 'pay' => ['2026-08-08 12:00', 165000]]],
            ['BH_BM', 1000000, 5, 'SL2', '2026-09-09 09:00', ['approve' => '2026-09-10 10:00']],
            ['KS_LO', 400000, 2, 'SL3', '2026-09-11 09:00', []],
        ];

        foreach ($loans as [$staff, $amount, $sessions, $code, $requested, $steps]) {
            $reason = ['SL1' => 'Kununua pikipiki ya kufuatilia wateja', 'SL2' => 'Ada ya shule ya watoto muhula wa tatu', 'SL3' => 'Matengenezo ya nyumba'][$code].' '.Context::marker($code);
            $loan = fn (): ?StaffLoan => StaffLoan::where('employee_id', $this->ctx->staff($staff)->id)->where('reason', $reason)->first();

            $this->ctx->timeline->at($requested, "staff loan {$code}", function () use ($staff, $amount, $sessions, $reason): void {
                $employee = $this->ctx->staff($staff);
                $this->ctx->api->call($this->hr(), 'POST', 'hrm/staff-loans', [
                    'blanch_id' => $employee->branch_id,
                    'empl_id' => $employee->id,
                    'category_id' => StaffLoanCategory::where('company_id', $this->ctx->company->id)->where('name', OrganisationPhase::STAFF_LOAN_CATEGORY)->value('id'),
                    'loan_amount' => $amount,
                    'day' => 'monthly',
                    'session' => $sessions,
                    'reason' => $reason,
                ]);
            }, fn (): bool => $loan() !== null);

            if (isset($steps['approve'])) {
                $this->ctx->timeline->at($steps['approve'], "staff loan {$code} approved", fn () => $this->ctx->api->call($this->hr(), 'POST', "hrm/staff-loans/{$loan()->id}/approve"), fn (): bool => $loan()?->status !== StaffCreditStatus::Submitted->value);
            }
            if (isset($steps['finance'])) {
                $this->ctx->timeline->at($steps['finance'], "staff loan {$code} finance approved", fn () => $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/staff-loans/{$loan()->id}/finance-approve"), fn (): bool => ! in_array($loan()?->status, [StaffCreditStatus::Submitted->value, ...StaffCreditStatus::reviewed()], true));
            }
            if (isset($steps['disburse'])) {
                $this->ctx->timeline->at($steps['disburse'], "staff loan {$code} disbursed", fn () => $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/staff-loans/{$loan()->id}/disburse"), fn (): bool => ! in_array($loan()?->status, StaffCreditStatus::awaitingDisbursement(), true));
            }
            if (isset($steps['pay'])) {
                [$at, $payment] = $steps['pay'];
                $this->ctx->timeline->at($at, "staff loan {$code} repayment", fn () => $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/staff-loans/{$loan()->id}/pay", ['amount' => $payment]), fn (): bool => $loan()?->payments()->exists() === true);
            }
        }

        $reason = 'Dharura ya kifamilia '.Context::marker('SFW1');
        $claim = fn (): ?StaffFundWithdrawal => StaffFundWithdrawal::where('reason', $reason)->first();
        $this->ctx->timeline->at('2026-08-28 11:00', 'staff benefit claim KB_LO2 prepared', function () use ($reason): void {
            $this->ctx->api->call($this->hr(), 'POST', 'hrm/staff-fund/claims', ['empl_id' => $this->ctx->staff('KB_LO2')->id, 'amount' => 40000, 'reason' => $reason]);
        }, fn (): bool => $claim() !== null);
        $this->ctx->timeline->at('2026-08-28 14:00', 'staff benefit claim KB_LO2 approved', function () use ($claim): void {
            $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/staff-fund/claims/{$claim()->id}/approve");
        }, fn (): bool => in_array($claim()?->status, [StaffFundWithdrawal::STATUS_APPROVED, StaffFundWithdrawal::STATUS_PAID], true));
        $this->ctx->timeline->at('2026-08-28 15:00', 'staff benefit claim KB_LO2 paid', function () use ($claim): void {
            $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "hrm/staff-fund/claims/{$claim()->id}/pay");
        }, fn (): bool => $claim()?->status === StaffFundWithdrawal::STATUS_PAID);
    }

    private function reviews(): void
    {
        $june = [['KB_BM', 4, 'Excellent'], ['KB_LO', 5, 'Excellent'], ['KS_BM', 3, 'Good'], ['KS_LO', 3, 'Fair'], ['KM_BM', 4, 'Good'], ['KM_LO', 4, 'Good'], ['BH_BM', 3, 'Good'], ['BH_LO', 2, 'Fair'], ['UV_BM', 4, 'Excellent'], ['UV_LO', 3, 'Good']];
        foreach ($june as [$staff, $rating, $discipline]) {
            $this->review($staff, '2026-06', '2026-07-06 10:00', $rating, $discipline);
        }
        foreach ([['KB_LO2', 4, 'Good'], ['KM_LO2', 3, 'Good'], ['BH_LO', 3, 'Good'], ['UV_LO', 4, 'Excellent'], ['KK_LO', 5, 'Excellent']] as [$staff, $rating, $discipline]) {
            $this->review($staff, '2026-08', '2026-09-03 10:00', $rating, $discipline);
        }
    }

    private function review(string $staff, string $period, string $at, int $rating, string $discipline): void
    {
        $this->ctx->timeline->at($at, "performance review {$staff} {$period}", function () use ($staff, $period, $rating, $discipline): void {
            $this->ctx->api->call($this->hr(), 'POST', 'hrm/performance/reviews', [
                'empl_id' => $this->ctx->staff($staff)->id,
                'period' => $period,
                'targets' => 'Wateja wapya 8, mikopo mipya TZS 12,000,000, PAR30 chini ya 5%',
                'discipline' => $discipline,
                'rating' => $rating,
                'remarks' => $rating >= 4 ? 'Amefikia malengo ya mwezi; aendelee na ufuatiliaji wa wateja.' : 'Aongeze ufuatiliaji wa marejesho yaliyochelewa.',
            ]);
        }, fn (): bool => StaffPerformanceReview::where('employee_id', $this->ctx->staff($staff)->id)->whereDate('period', "{$period}-01")->exists());
    }

    private function attendance(): void
    {
        $staff = ['KB_BM', 'KB_LO', 'KB_TL', 'KS_BM', 'KS_TL', 'KM_BM', 'KM_LO', 'KM_TL', 'BH_LO', 'BH_TL', 'UV_LO', 'UV_TL'];

        for ($day = CarbonImmutable::parse('2026-08-24'); $day->lte(CarbonImmutable::parse('2026-09-12')); $day = $day->addDay()) {
            if ($day->isSunday()) {
                continue;
            }
            foreach ($staff as $number => $key) {
                $seed = $day->dayOfYear * 7 + $number * 13;
                $record = match (true) {
                    $key === 'BH_LO' && in_array($day->toDateString(), ['2026-09-07', '2026-09-08'], true) => ['status' => 'leave', 'check_in' => null, 'check_out' => null, 'remarks' => 'Likizo ya matibabu (imeidhinishwa)'],
                    $seed % 23 === 0 => ['status' => 'absent', 'check_in' => null, 'check_out' => null, 'remarks' => 'Hakufika bila taarifa'],
                    $seed % 5 === 0 => ['status' => 'late', 'check_in' => sprintf('08:%02d', 10 + $seed % 35), 'check_out' => '17:30', 'remarks' => 'Amechelewa - usafiri'],
                    $seed % 7 === 0 && str_ends_with($key, '_LO') => ['status' => 'present', 'check_in' => '07:40', 'check_out' => '15:10', 'remarks' => 'Kazi ya nje: ziara za wateja (field work), ameondoka mapema'],
                    default => ['status' => 'present', 'check_in' => sprintf('07:%02d', 35 + $seed % 20), 'check_out' => $day->isSaturday() ? '13:00' : sprintf('17:%02d', 5 + $seed % 25), 'remarks' => null],
                };

                $this->ctx->timeline->at($day->setTime(18, 0), "attendance {$key} {$day->toDateString()}", function () use ($key, $day, $record): void {
                    $this->ctx->api->call($this->hr(), 'POST', 'hrm/attendance', ['empl_id' => $this->ctx->staff($key)->id, 'date' => $day->toDateString()] + $record);
                }, fn (): bool => Attendance::where('employee_id', $this->ctx->staff($key)->id)->whereDate('date', $day->toDateString())->exists());
            }
        }

        foreach ([['KB_TL', '07:52'], ['KM_TL', '08:17'], ['UV_LO', '07:41'], ['KS_BM', '07:58']] as [$key, $time]) {
            $this->ctx->timeline->at("2026-09-14 {$time}", "check-in {$key} today", function () use ($key): void {
                $this->ctx->api->call($this->ctx->staff($key), 'POST', 'hrm/attendance/check-in');
            }, fn (): bool => Attendance::where('employee_id', $this->ctx->staff($key)->id)->whereDate('date', '2026-09-14')->whereNotNull('check_in')->exists());
        }
    }
}
