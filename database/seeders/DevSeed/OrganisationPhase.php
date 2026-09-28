<?php

namespace Database\Seeders\DevSeed;

use App\Models\Employee;
use App\Models\ExpenseType;
use App\Models\LoanCategory;
use App\Models\Region;
use App\Models\Role;
use App\Models\StaffLoanCategory;
use App\Models\Zone;
use Carbon\CarbonImmutable;

/**
 * Branches, expense types, loan categories (with fees and branch assignment) and staff — registered through Settings and
 * HRM exactly as an administrator / HR officer would.
 */
final class OrganisationPhase
{
    public const STAFF_LOAN_CATEGORY = 'DEV MKOPO WA MFANYAKAZI';

    public function __construct(private readonly Context $ctx) {}

    public function register(): void
    {
        $this->branches();
        $this->staff();
        $this->expenseTypes();
        $this->loanCategories();
    }

    private function branches(): void
    {
        foreach (Catalog::NEW_BRANCHES as $index => $code) {
            [$name, $phone, $type, $region] = Catalog::BRANCHES[$code];

            $this->ctx->timeline->at(CarbonImmutable::parse('2026-03-02 09:00')->addMinutes($index * 10), "branch {$name}", function () use ($name, $phone, $type, $region): void {
                $this->ctx->api->call($this->ctx->demo('super_admin'), 'POST', 'settings/branches', [
                    'blanch_name' => $name,
                    'region_id' => Region::where('name', $region)->value('id'),
                    'blanch_no' => $phone,
                    'branch_type' => $type,
                    'zone_id' => Zone::where('company_id', $this->ctx->company->id)->where('name', Catalog::ZONE)->value('id'),
                ]);
            }, fn (): bool => $this->ctx->branchExists($code));
        }
    }

    private function staff(): void
    {
        foreach (Catalog::STAFF as $key => [$first, $middle, $last, $gender, $dob, $branch, $role, $position, $salary, $hiredAt, $finalStatus]) {
            $this->ctx->timeline->at($hiredAt, "staff {$first} {$last}", function () use ($key, $first, $middle, $last, $gender, $dob, $branch, $role, $position, $salary): void {
                $companyRoles = Role::where('company_id', $this->ctx->company->id)->pluck('id', 'key');
                $hr = $key === 'HQ_HR' ? $this->ctx->demo('hr') : $this->ctx->staff('HQ_HR');
                $salaryType = match ($role) {
                    'hr', 'admin', 'finance', 'credit_officer' => 'hq',
                    'zone_manager' => 'zone_manager',
                    default => 'branch',
                };

                $this->ctx->api->call($hr, 'POST', 'hrm/staff', [
                    'empl_name' => $first,
                    'emp_mname' => $middle,
                    'emp_lname' => $last,
                    'empl_no' => Context::staffPhone($key),
                    'date_birth' => $dob,
                    'empl_email' => strtolower("{$first}.{$last}@devseed.test"),
                    'blanch_id' => $this->ctx->branch($branch)->id,
                    'position_id' => $position,
                    'username' => strtolower("{$first}.{$last}"),
                    'empl_sex' => $gender,
                    'role_id' => $companyRoles[$role],
                    'zone_id' => $role === 'zone_manager' ? Zone::where('company_id', $this->ctx->company->id)->where('name', Catalog::ZONE)->value('id') : null,
                    'password' => 'password',
                    'salary' => $salary,
                    'salary_type' => $salaryType,
                    'commission_eligible' => $salaryType !== 'hq',
                    'payment_method' => $salary > 450000 ? 'bank' : 'mobile',
                    'account_name' => strtoupper("{$first} {$middle} {$last}"),
                    'account_number' => $salary > 450000 ? '2071'.substr(Context::staffPhone($key), -6).'01' : Context::staffPhone($key),
                ]);
            }, fn (): bool => $this->ctx->staffExists($key));

            if ($finalStatus === 'rejected') {
                $this->ctx->timeline->at('2026-03-27 11:00', "reject staff {$first} {$last}", function () use ($key): void {
                    $this->ctx->api->call($this->ctx->staff('HQ_HR'), 'POST', "hrm/staff/{$this->ctx->staff($key)->id}/reject");
                }, fn (): bool => $this->ctx->staff($key)->status === 'rejected');
            }
            if ($finalStatus === 'blocked') {
                $this->ctx->timeline->at('2026-08-31 16:00', "block staff {$first} {$last}", function () use ($key): void {
                    $this->ctx->api->call($this->ctx->demo('super_admin'), 'POST', "hrm/staff/{$this->ctx->staff($key)->id}/block");
                }, fn (): bool => $this->ctx->staff($key)->status === 'blocked');
            }
        }

        $this->ctx->timeline->at('2026-03-25 11:30', 'staff loan category', function (): void {
            $this->ctx->api->call($this->ctx->staff('HQ_HR'), 'POST', 'hrm/staff-loan-categories', [
                'category_name' => self::STAFF_LOAN_CATEGORY,
                'from_amount' => 100000,
                'to_amount' => 1500000,
                'interest' => 10,
                'duration' => 'monthly',
                'from_repayment' => 1,
                'to_repayment' => 6,
                'fee' => 5000,
            ]);
        }, fn (): bool => StaffLoanCategory::where('company_id', $this->ctx->company->id)->where('name', self::STAFF_LOAN_CATEGORY)->exists());
    }

    private function expenseTypes(): void
    {
        $nameField = ['branch' => 'ex_name', 'hq' => 'exp_desc', 'bank' => 'expenses_name'];

        foreach (Catalog::EXPENSE_TYPES as [$scope, $name]) {
            $this->ctx->timeline->at('2026-03-25 10:00', "expense type {$name}", function () use ($scope, $name, $nameField): void {
                $this->ctx->api->call($this->ctx->demo('super_admin'), 'POST', 'expenses/types', ['scope' => $scope, $nameField[$scope] => $name]);
            }, fn (): bool => ExpenseType::where('company_id', $this->ctx->company->id)->where('scope', $scope)->where('name', $name)->exists());
        }
    }

    private function loanCategories(): void
    {
        $admin = fn (): Employee => $this->ctx->demo('super_admin');

        foreach (Catalog::LOAN_CATEGORIES as $key => $definition) {
            $exists = fn (): bool => LoanCategory::where('company_id', $this->ctx->company->id)->where('name', $definition['name'])->exists();

            $this->ctx->timeline->at('2026-03-25 09:00', "loan category {$definition['name']}", function () use ($admin, $definition): void {
                $this->ctx->api->call($admin(), 'POST', 'settings/loan-categories', [
                    'loan_name' => $definition['name'],
                    'loan_price' => $definition['from'],
                    'loan_perday' => $definition['to'],
                    'interest_formular' => $definition['rate'],
                    'formular' => $definition['formula'],
                    'duration' => $definition['duration'],
                    'from_repayment' => $definition['rep'][0],
                    'to_repayment' => $definition['rep'][1],
                    'fee_deduct' => $definition['fee_deduct'],
                    'penart' => $definition['penalty'],
                    'aprove_status' => $definition['approve'],
                    'topup_percent' => $definition['topup'],
                    'take_home_percent' => $definition['take_home'],
                    'customer_type_id' => $this->ctx->customerType($definition['type'])->id,
                    'requires_mandate' => $definition['mandate'],
                    'freeze_time_days' => $definition['freeze'],
                ]);
            }, $exists);

            [$feeType, $feeValue, $insurance] = $definition['fee'];
            $this->ctx->timeline->at('2026-03-25 09:15', "loan fee {$definition['name']}", function () use ($admin, $key, $definition, $feeType, $feeValue, $insurance): void {
                $this->ctx->api->call($admin(), 'PUT', "settings/loan-fees/{$this->ctx->category($key)->id}", [
                    'loan_name' => $definition['name'],
                    'loan_price' => $definition['from'],
                    'loan_perday' => $definition['to'],
                    'interest_formular' => $definition['rate'],
                    'fee_category_type' => $feeType,
                    'fee_value' => $feeValue,
                    'insurance' => $insurance,
                ]);
            }, fn (): bool => $exists() && $this->ctx->category($key)->fee_type === strtolower($feeType) && abs((float) $this->ctx->category($key)->fee_value - $feeValue) < 0.01 && abs((float) $this->ctx->category($key)->insurance - $insurance) < 0.01);

            foreach (['KK', 'MS', 'LN', ...Catalog::NEW_BRANCHES] as $branch) {
                $this->ctx->timeline->at('2026-03-25 09:30', "assign {$definition['name']} to {$branch}", function () use ($admin, $key, $branch): void {
                    $this->ctx->api->call($admin(), 'POST', "settings/loan-categories/{$this->ctx->category($key)->id}/branches/{$this->ctx->branch($branch)->id}");
                }, fn (): bool => $exists() && $this->ctx->category($key)->branches()->whereKey($this->ctx->branch($branch)->id)->exists());
            }
        }
    }
}
