<?php

use App\Http\Controllers\Api\V1\Hrm\AllowanceController;
use App\Http\Controllers\Api\V1\Hrm\AttendanceController;
use App\Http\Controllers\Api\V1\Hrm\CategoryController;
use App\Http\Controllers\Api\V1\Hrm\CommissionController;
use App\Http\Controllers\Api\V1\Hrm\DeductionController;
use App\Http\Controllers\Api\V1\Hrm\LeaveController;
use App\Http\Controllers\Api\V1\Hrm\MyPortalController;
use App\Http\Controllers\Api\V1\Hrm\MyStaffCreditController;
use App\Http\Controllers\Api\V1\Hrm\NegligenceDeductionController;
use App\Http\Controllers\Api\V1\Hrm\PayrollController;
use App\Http\Controllers\Api\V1\Hrm\PerformanceController;
use App\Http\Controllers\Api\V1\Hrm\SalaryAdvanceController;
use App\Http\Controllers\Api\V1\Hrm\SalaryChangeController;
use App\Http\Controllers\Api\V1\Hrm\SettingController;
use App\Http\Controllers\Api\V1\Hrm\StaffController;
use App\Http\Controllers\Api\V1\Hrm\StaffFundController;
use App\Http\Controllers\Api\V1\Hrm\StaffLoanController;
use App\Http\Controllers\Api\V1\Hrm\StaffPrivilegeController;
use Illuminate\Support\Facades\Route;

Route::prefix('hrm')->name('hrm.')->group(function (): void {
    Route::controller(StaffController::class)->group(function (): void {
        Route::get('staff', 'index')->name('staff.index');
        Route::post('staff', 'store')->name('staff.store');
        Route::post('staff/block-all', 'blockAll')->name('staff.block-all');
        Route::get('staff/{employee}', 'show')->name('staff.show');
        Route::put('staff/{employee}', 'update')->name('staff.update');
        Route::delete('staff/{employee}', 'destroy')->name('staff.destroy');
        Route::post('staff/{employee}/photo', 'photo')->name('staff.photo');
        Route::put('staff/{employee}/salary', 'salary')->name('staff.salary');
        Route::put('staff/{employee}/password', 'password')->name('staff.password');
        Route::post('staff/{employee}/block', 'toggleBlock')->name('staff.block');
        Route::post('staff/{employee}/reject', 'reject')->name('staff.reject');
        Route::post('staff/{employee}/reset-password', 'resetPassword')->name('staff.reset-password');
        Route::get('branches', 'branches')->name('branches');
    });

    Route::get('staff/{employee}/privileges', [StaffPrivilegeController::class, 'show'])->name('staff.privileges.show');
    Route::put('staff/{employee}/privileges', [StaffPrivilegeController::class, 'update'])->name('staff.privileges.update');

    Route::controller(SettingController::class)->group(function (): void {
        Route::get('settings', 'show')->name('settings.show');
        Route::put('settings', 'update')->name('settings.update');
        Route::get('options/roles', 'roles')->name('options.roles');
        Route::get('options/zones', 'zones')->name('options.zones');
    });

    Route::get('leaves', [LeaveController::class, 'index'])->name('leaves.index');
    Route::post('leaves', [LeaveController::class, 'store'])->name('leaves.store');
    Route::post('leaves/{leave}/decide', [LeaveController::class, 'decide'])->name('leaves.decide');

    Route::get('allowances', [AllowanceController::class, 'index'])->name('allowances.index');
    Route::post('allowances', [AllowanceController::class, 'store'])->name('allowances.store');
    Route::post('allowances/{allowance}/approve', [AllowanceController::class, 'approve'])->name('allowances.approve');
    Route::post('allowances/{allowance}/reject', [AllowanceController::class, 'reject'])->name('allowances.reject');
    Route::post('allowances/{allowance}/stop', [AllowanceController::class, 'stop'])->name('allowances.stop');

    Route::controller(NegligenceDeductionController::class)->group(function (): void {
        Route::get('negligence-deductions', 'index')->name('negligence-deductions.index');
        Route::post('negligence-deductions', 'store')->name('negligence-deductions.store');
        Route::post('negligence-deductions/{negligenceDeduction}/approve', 'approve')->name('negligence-deductions.approve');
        Route::post('negligence-deductions/{negligenceDeduction}/reject', 'reject')->name('negligence-deductions.reject');
    });

    Route::get('deductions', [DeductionController::class, 'index'])->name('deductions.index');
    Route::post('deductions', [DeductionController::class, 'store'])->name('deductions.store');

    Route::controller(CategoryController::class)->group(function (): void {
        Route::get('staff-loan-categories', 'loanCategories')->name('staff-loan-categories.index');
        Route::post('staff-loan-categories', 'storeLoanCategory')->name('staff-loan-categories.store');
        Route::put('staff-loan-categories/{category}', 'updateLoanCategory')->name('staff-loan-categories.update');
        Route::delete('staff-loan-categories/{category}', 'destroyLoanCategory')->name('staff-loan-categories.destroy');
        Route::get('staff-salary-advance-categories', 'advanceCategories')->name('staff-salary-advance-categories.index');
        Route::post('staff-salary-advance-categories', 'storeAdvanceCategory')->name('staff-salary-advance-categories.store');
        Route::put('staff-salary-advance-categories/{category}', 'updateAdvanceCategory')->name('staff-salary-advance-categories.update');
        Route::delete('staff-salary-advance-categories/{category}', 'destroyAdvanceCategory')->name('staff-salary-advance-categories.destroy');
    });

    Route::controller(StaffLoanController::class)->group(function (): void {
        Route::get('staff-loans', 'index')->name('staff-loans.index');
        Route::get('staff-loans/active', 'active')->name('staff-loans.active');
        Route::post('staff-loans', 'store')->name('staff-loans.store');
        Route::post('staff-loans/{loan}/approve', 'approve')->name('staff-loans.approve');
        Route::post('staff-loans/{loan}/finance-approve', 'financeApprove')->name('staff-loans.finance-approve');
        Route::post('staff-loans/{loan}/reject', 'reject')->name('staff-loans.reject');
        Route::post('staff-loans/{loan}/disburse', 'disburse')->name('staff-loans.disburse');
        Route::post('staff-loans/{loan}/pay', 'pay')->name('staff-loans.pay');
    });

    Route::controller(SalaryAdvanceController::class)->group(function (): void {
        Route::get('salary-advances', 'index')->name('salary-advances.index');
        Route::post('salary-advances', 'store')->name('salary-advances.store');
        Route::post('salary-advances/{advance}/approve', 'approve')->name('salary-advances.approve');
        Route::post('salary-advances/{advance}/finance-approve', 'financeApprove')->name('salary-advances.finance-approve');
        Route::post('salary-advances/{advance}/reject', 'reject')->name('salary-advances.reject');
        Route::post('salary-advances/{advance}/disburse', 'disburse')->name('salary-advances.disburse');
    });

    Route::controller(MyStaffCreditController::class)->group(function (): void {
        Route::get('my/staff-credit-categories', 'categories')->name('my.staff-credit-categories');
        Route::get('my/staff-loans', 'loans')->name('my.staff-loans.index');
        Route::post('my/staff-loans', 'storeLoan')->name('my.staff-loans.store');
        Route::get('my/salary-advances', 'advances')->name('my.salary-advances.index');
        Route::post('my/salary-advances', 'storeAdvance')->name('my.salary-advances.store');
    });

    Route::controller(MyPortalController::class)->group(function (): void {
        Route::get('my/overview', 'overview')->name('my.overview');
        Route::get('my/payslips', 'payslips')->name('my.payslips');
        Route::get('my/staff-fund', 'staffFund')->name('my.staff-fund');
        Route::get('my/commission', 'commission')->name('my.commission');
        Route::get('my/allowances', 'allowances')->name('my.allowances');
        Route::get('my/negligence', 'negligence')->name('my.negligence');
        Route::get('my/repayments', 'repayments')->name('my.repayments');
    });

    Route::controller(SalaryChangeController::class)->group(function (): void {
        Route::get('salary-changes', 'index')->name('salary-changes.index');
        Route::post('salary-changes/{salaryChange}/approve', 'approve')->name('salary-changes.approve');
        Route::post('salary-changes/{salaryChange}/reject', 'reject')->name('salary-changes.reject');
    });

    Route::controller(PayrollController::class)->group(function (): void {
        Route::get('payroll', 'show')->name('payroll.show');
        Route::post('payroll/generate', 'generate')->name('payroll.generate');
        Route::post('payroll/{run}/approve', 'approve')->name('payroll.approve');
        Route::post('payroll/{run}/pay', 'pay')->name('payroll.pay');
        Route::get('salary-payments', 'payments')->name('salary-payments.index');
        Route::get('salary-payments/{payment}', 'payslip')->name('salary-payments.show');
    });

    Route::controller(CommissionController::class)->group(function (): void {
        Route::get('commission', 'show')->name('commission.show');
        Route::post('commission/calculate', 'calculate')->name('commission.calculate');
        Route::get('commission/payments', 'payments')->name('commission.payments.index');
        Route::get('commission/mine', 'mine')->name('commission.mine');
        Route::post('commission/payments/finalize', 'finalize')->name('commission.payments.finalize');
        Route::post('commission/payments/request', 'requestPayment')->name('commission.payments.request');
        Route::post('commission/payments/approve', 'approve')->name('commission.payments.approve');
        Route::post('commission/payments/reject', 'reject')->name('commission.payments.reject');
        Route::post('commission/payments/pay', 'pay')->name('commission.payments.pay');
    });

    Route::get('staff-fund', [StaffFundController::class, 'show'])->name('staff-fund.show');
    Route::controller(StaffFundController::class)->group(function (): void {
        Route::get('staff-fund/claims', 'claims')->name('staff-fund.claims.index');
        Route::post('staff-fund/claims', 'withdraw')->name('staff-fund.claims.store');
        Route::get('staff-fund/entitlements/{employee}', 'entitlement')->name('staff-fund.entitlements.show');
        Route::post('staff-fund/claims/{claim}/review', 'reviewClaim')->name('staff-fund.claims.review');
        Route::post('staff-fund/claims/{claim}/approve', 'approveClaim')->name('staff-fund.claims.approve');
        Route::post('staff-fund/claims/{claim}/reject', 'rejectClaim')->name('staff-fund.claims.reject');
        Route::post('staff-fund/claims/{claim}/pay', 'payClaim')->name('staff-fund.claims.pay');
    });

    Route::controller(AttendanceController::class)->group(function (): void {
        Route::get('attendance', 'index')->name('attendance.index');
        Route::post('attendance', 'store')->name('attendance.store');
        Route::get('attendance/summary', 'summary')->name('attendance.summary');
        Route::post('attendance/check-in', 'checkIn')->name('attendance.check-in');
        Route::post('attendance/check-out', 'checkOut')->name('attendance.check-out');
    });

    Route::get('performance', [PerformanceController::class, 'index'])->name('performance.index');
    Route::get('performance/reviews', [PerformanceController::class, 'reviews'])->name('performance.reviews');
    Route::post('performance/reviews', [PerformanceController::class, 'storeReview'])->name('performance.reviews.store');
});
