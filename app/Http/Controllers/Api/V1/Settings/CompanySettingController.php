<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Settings\CompanyRequest;
use App\Http\Requests\Settings\DividendSettingRequest;
use App\Models\AuditLog;
use App\Models\Company;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Company-wide settings: Company Profile (live admin/setting), Penalty Setting (admin/penart_setting)
 * Reserve Setting (admin/reserve_setting) and Dividend Settings.
 */
class CompanySettingController extends ApiController
{
    /**
     * Live "Calculation Type" option values mapped to companies.penalty_type.
     *
     * @var array<string, string>
     */
    public const PENALTY_TYPES = ['PERCENTAGE VALUE' => 'percentage', 'MONEY VALUE' => 'money'];

    public function company(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $company = $this->currentCompany()->load('region');

        return response()->json(['data' => [
            'id' => $company->id,
            'name' => $company->name,
            'registration_number' => $company->registration_number,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'region_id' => $company->region_id,
            'region' => $company->region?->name,
            'logo_url' => $company->logo ? Storage::disk('public')->url($company->logo) : null,
        ]]);
    }

    public function updateCompany(CompanyRequest $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $this->audited($this->currentCompany(), 'Company.profile_updated', $request->companyData(), $request);

        return $this->message('Company Profile Updated successfully');
    }

    public function logo(Request $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $request->validate(['comp_logo' => ['required', 'image', 'max:2048']]);

        $company = $this->currentCompany();
        $previousLogo = $company->logo;

        $company->update(['logo' => $request->file('comp_logo')->store('logos', 'public')]);

        if ($previousLogo !== null) {
            Storage::disk('public')->delete($previousLogo);
        }

        return $this->message('Company Logo Updated successfully');
    }

    /**
     * Change the signed-in employee's password (same page as the company profile on the live system).
     */
    public function password(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'oldpass' => ['required', 'string'],
            'newpass' => ['required', 'string', 'min:4'],
            'passconf' => ['required', 'same:newpass'],
        ], [
            'passconf.same' => 'New Password and Confirm Password do not match',
        ]);

        $employee = $this->currentEmployee();

        if (! Hash::check($validated['oldpass'], $employee->password)) {
            throw ValidationException::withMessages(['oldpass' => 'Old Password is incorrect']);
        }

        $employee->update(['password' => $validated['newpass']]);

        return $this->message('Password Changed successfully');
    }

    public function penalty(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $company = $this->currentCompany();

        return response()->json(['data' => [
            'action_penart' => $company->penalty_type === 'money' ? 'MONEY VALUE' : 'PERCENTAGE VALUE',
            'penart' => (float) $company->penalty_value,
        ]]);
    }

    /**
     * The live row "delete" removes the setting; here it submits a zero amount, which disables penalties.
     */
    public function updatePenalty(Request $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $validated = $request->validate([
            'action_penart' => ['required', Rule::in(array_keys(self::PENALTY_TYPES))],
            'penart' => ['required', 'numeric', 'min:0', Rule::when($request->input('action_penart') === 'PERCENTAGE VALUE', ['max:100'])],
        ]);

        $this->audited($this->currentCompany(), 'Company.penalty_updated', [
            'penalty_type' => self::PENALTY_TYPES[$validated['action_penart']],
            'penalty_value' => $validated['penart'],
        ], $request);

        return $this->message('Penalty Setting Updated successfully');
    }

    public function reserve(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        return response()->json(['data' => ['reserve' => (float) $this->currentCompany()->reserve_percent]]);
    }

    /**
     * Documents (ACCOUNT OVERVIEW "Reserve Account"): the reserve percentage is cut from interest on every repayment.
     */
    public function updateReserve(Request $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $validated = $request->validate(['reserve' => ['required', 'numeric', 'min:0', 'max:100']]);

        $this->audited($this->currentCompany(), 'Company.reserve_updated', ['reserve_percent' => $validated['reserve']], $request);

        return $this->message('Reserve Setting Updated successfully');
    }

    public function dividend(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $company = $this->currentCompany();

        return response()->json(['data' => [
            'dividend_percent' => (float) $company->dividend_shareholder_percent,
            'reinvest_percent' => (float) $company->dividend_reinvest_percent,
        ]]);
    }

    /**
     * Documents (ACCOUNT OVERVIEW "16. Dividend Account": Profit → Dividend, 70% → Principal (Reinvestment), 30% →
     * Shareholders): the company's split, used by every later dividend declaration. Past declarations keep the
     * percentages they were declared with.
     */
    public function updateDividend(DividendSettingRequest $request): JsonResponse
    {
        $this->audited($this->currentCompany(), 'Company.dividend_settings_updated', [
            'dividend_shareholder_percent' => $request->percent('dividend_percent'),
            'dividend_reinvest_percent' => $request->percent('reinvest_percent'),
        ], $request);

        return $this->message('Dividend Settings Updated successfully');
    }

    public function loanFreeze(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        return response()->json(['data' => ['loan_freeze_days' => (int) $this->currentCompany()->loan_freeze_days]]);
    }

    /**
     * Default Freeze Time (Days) prefilled for NEW loan categories. The freeze a loan actually gets comes from its loan
     * category (loan_categories.freeze_time_days); changing this default never changes existing categories or loans.
     */
    public function updateLoanFreeze(Request $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $validated = $request->validate(['loan_freeze_days' => ['required', 'integer', 'min:0', 'max:365']]);

        $this->audited($this->currentCompany(), 'Company.loan_freeze_updated', ['loan_freeze_days' => $validated['loan_freeze_days']], $request);

        return $this->message('Loan Freeze Period Updated successfully');
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function audited(Company $company, string $action, array $values, Request $request): void
    {
        $before = $company->only(array_keys($values));
        $company->update($values);

        AuditLog::create([
            'company_id' => $company->id,
            'employee_id' => $this->currentEmployee()->id,
            'action' => $action,
            'auditable_type' => $company->getMorphClass(),
            'auditable_id' => $company->id,
            'before' => $before,
            'after' => $values,
            'ip_address' => $request->ip(),
        ]);
    }
}
