<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Services\AccessControl;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Employee extends Authenticatable
{
    /** @use HasFactory<EmployeeFactory> */
    use Auditable, HasApiTokens, HasFactory, Notifiable;

    /**
     * Privileges an employee can be granted (HRM → privilege page).
     *
     * @var array<string, string>
     */
    public const PRIVILEGES = [
        'apply' => 'APPLY LOAN',
        'aprove' => 'APPROVE',
        'bank' => 'BANK',
        'bank_password' => 'BANK PASSWORD',
        'customer' => 'CUSTOMER',
        'debit' => 'DEBT PENDING',
        'expenses' => 'EXPENSES',
        'float' => 'FLOAT',
        'group' => 'GROUP',
        'loan' => 'LOAN',
        'report' => 'REPORT',
        'saving' => 'SAVING',
        'teller' => 'TELLER',
    ];

    /**
     * @var array<string, string>
     */
    public const POSITIONS = [
        'employee' => 'Employee',
        'hq' => 'Hq',
        'zone' => 'Zone',
        'admin' => 'Admin',
    ];

    public const ACCOUNT_STAFF = 'staff';

    public const ACCOUNT_SHAREHOLDER = 'shareholder';

    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password', 'remember_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
        ];
    }

    /**
     * Staff accounts only: excludes shareholder portal logins (employees.account_type = shareholder) from HRM, payroll,
     * commission, messaging, goals, officer lists and staff counts. Staff who are also shareholders stay staff.
     *
     * @param  Builder<Employee>  $query
     */
    public function scopeStaff(Builder $query): void
    {
        $query->where($query->qualifyColumn('account_type'), '!=', self::ACCOUNT_SHAREHOLDER);
    }

    /**
     * A portal-only login created for a shareholder (holds no staff permission and no data scope).
     */
    public function isShareholderAccount(): bool
    {
        return $this->account_type === self::ACCOUNT_SHAREHOLDER;
    }

    /**
     * The shareholder record linked to this login (share_holders.employee_id), if any.
     */
    public function shareHolder(): HasOne
    {
        return $this->hasOne(ShareHolder::class);
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim(implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]))));
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->photo ? asset('storage/'.$this->photo) : '/assets/img/male.jpeg');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        return app(AccessControl::class)->permissionsFor($this);
    }

    /**
     * Per-employee grants/revocations applied on top of the role's permissions.
     */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(EmployeePermission::class);
    }

    public function privileges(): HasMany
    {
        return $this->hasMany(EmployeePrivilege::class);
    }

    public function salaryInfo(): HasOne
    {
        return $this->hasOne(EmployeeSalary::class);
    }

    public function allowances(): HasMany
    {
        return $this->hasMany(StaffAllowance::class);
    }

    public function deductions(): HasMany
    {
        return $this->hasMany(StaffDeduction::class);
    }

    public function staffLoans(): HasMany
    {
        return $this->hasMany(StaffLoan::class);
    }

    public function salaryAdvances(): HasMany
    {
        return $this->hasMany(StaffSalaryAdvance::class);
    }

    public function salaryPayments(): HasMany
    {
        return $this->hasMany(SalaryPayment::class);
    }
}
