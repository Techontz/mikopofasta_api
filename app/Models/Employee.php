<?php

namespace App\Models;

use App\Http\Middleware\ApplyActiveRole;
use App\Models\Concerns\Auditable;
use App\Services\AccessControl;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Employee extends Authenticatable
{
    /**
     * The primary role id while the request acts as another assigned role ({@see self::actAsRole()}).
     */
    public ?int $switchedFromRoleId = null;

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

    /**
     * The role this request acts as: the primary role (employees.role_id), or another assigned role the session switched
     * to ({@see ApplyActiveRole}).
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * The stored role (employees.role_id), whichever role this request acts as.
     */
    public function primaryRoleId(): ?int
    {
        $id = $this->switchedFromRoleId ?? $this->role_id;

        return $id === null ? null : (int) $id;
    }

    /**
     * Act as another assigned role for this request only: the change is never saved (role_id stays the primary role).
     */
    public function actAsRole(Role $role): void
    {
        $this->switchedFromRoleId ??= $this->role_id === null ? null : (int) $this->role_id;
        $this->setAttribute('role_id', $role->id);
        $this->syncOriginalAttribute('role_id');
        $this->setRelation('role', $role->loadMissing('permissions'));
    }

    /**
     * Roles assigned besides the primary one (multi-role, enhancement §13).
     */
    public function additionalRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'employee_roles')->withPivot('assigned_by')->withTimestamps();
    }

    /**
     * Every role the employee may act as: the primary role first, then the additional ones.
     *
     * @return Collection<int, Role>
     */
    public function assignedRoles(): Collection
    {
        $primaryId = $this->primaryRoleId();
        $primary = $primaryId ? Role::query()->whereKey($primaryId)->get() : new Collection;

        return $primary->concat($this->additionalRoles()->where('roles.company_id', $this->company_id)->whereKeyNot($primaryId)->orderBy('roles.id')->get())->values();
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
