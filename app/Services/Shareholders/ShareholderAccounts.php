<?php

namespace App\Services\Shareholders;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Role;
use App\Models\ShareHolder;
use App\Services\AccessControl;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shareholder login accounts. A login is an `employees` row (the one authentication system, Sanctum tokens) linked 1:1 to
 * a `share_holders` row through share_holders.employee_id:
 *
 *  - a phone that belongs to no login → a new portal-only account (account_type shareholder, role shareholder, no branch,
 *    status active) with a random temporary password (hashed by the model cast) and must_change_password;
 *  - a phone of an existing STAFF employee of the same company → that employee is linked (no duplicate account; the staff
 *    role stays; the portal permissions come from the link);
 *  - a phone already linked to another shareholder, or used by a login of another company → refused (422).
 *
 * The plain temporary password is returned once to the caller (never stored, logged, audited or sent by SMS). Capital,
 * shares, dividends and journals are never touched here.
 */
class ShareholderAccounts
{
    public const ROLE_KEY = 'shareholder';

    public const PHONE_PATTERN = '/^\+?[0-9]{9,15}$/';

    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_LINKED = 'linked';

    public function __construct(private readonly AccessControl $access) {}

    /**
     * Register a shareholder and its login in ONE database transaction ($afterCreate — e.g. storing the photo — runs inside
     * it too): any failure rolls back both rows.
     *
     * @param  array<string, mixed>  $holderData
     * @param  Closure(ShareHolder): void|null  $afterCreate
     * @return array{share_holder: ShareHolder, account: Employee, outcome: string, credentials: array{login: string, temporary_password: string}|null}
     *
     * @throws ValidationException
     */
    public function register(array $holderData, int $companyId, Employee $admin, ?Closure $afterCreate = null): array
    {
        return DB::transaction(function () use ($holderData, $companyId, $admin, $afterCreate): array {
            $account = $this->resolveAccount($companyId, (string) $holderData['mobile'], $holderData['email'] ?? null, null, $holderData);

            $holder = ShareHolder::create($holderData + ['company_id' => $companyId, 'employee_id' => $account['employee']->id]);

            if ($afterCreate !== null) {
                $afterCreate($holder);
            }

            $this->audit($holder, $admin, $account['outcome']);

            return ['share_holder' => $holder, 'account' => $account['employee'], 'outcome' => $account['outcome'], 'credentials' => $account['credentials']];
        });
    }

    /**
     * Create (or link) the login of an existing shareholder that has none.
     *
     * @return array{share_holder: ShareHolder, account: Employee, outcome: string, credentials: array{login: string, temporary_password: string}|null}
     *
     * @throws ValidationException
     */
    public function provision(ShareHolder $holder, Employee $admin): array
    {
        return DB::transaction(function () use ($holder, $admin): array {
            $locked = ShareHolder::whereKey($holder->id)->lockForUpdate()->firstOrFail();
            if ($locked->employee_id !== null) {
                throw ValidationException::withMessages(['share_holder' => "{$locked->full_name} already has a login account."]);
            }

            $account = $this->resolveAccount((int) $locked->company_id, (string) $locked->mobile, $locked->email, $locked, [
                'first_name' => $locked->first_name ?? $locked->full_name,
                'middle_name' => $locked->middle_name,
                'last_name' => $locked->last_name,
                'gender' => $locked->gender,
                'date_of_birth' => $locked->date_of_birth?->toDateString(),
            ]);

            $locked->update(['employee_id' => $account['employee']->id]);
            $this->audit($locked, $admin, $account['outcome']);

            return ['share_holder' => $locked, 'account' => $account['employee'], 'outcome' => $account['outcome'], 'credentials' => $account['credentials']];
        });
    }

    /**
     * Keep the login phone / e-mail in step with an edited shareholder (called inside the update transaction, before the
     * shareholder row is saved), with the same duplicate checks as registration.
     *
     * @throws ValidationException
     */
    public function syncLogin(ShareHolder $holder, string $mobile, ?string $email): void
    {
        if ($holder->employee_id === null) {
            return;
        }

        $account = Employee::whereKey($holder->employee_id)->lockForUpdate()->firstOrFail();
        if ($account->phone !== $mobile) {
            $this->assertValidPhone($mobile);
            $owner = Employee::where('phone', $mobile)->whereKeyNot($account->id)->first();
            if ($owner !== null) {
                throw ValidationException::withMessages(['share_mobile' => $this->phoneTakenMessage($owner, (int) $holder->company_id)]);
            }
        }

        $account->fill(['phone' => $mobile] + ($email !== null && $account->isShareholderAccount() ? ['email' => $email] : []));
        if ($account->isDirty()) {
            $account->save();
        }
    }

    /**
     * New temporary password for a shareholder's portal login (shown once): sets must_change_password and signs every
     * session out. Staff logins linked to a shareholder are reset from HRM instead.
     *
     * @return array{login: string, temporary_password: string}
     *
     * @throws ValidationException
     */
    public function resetTemporaryPassword(ShareHolder $holder, Employee $admin): array
    {
        return DB::transaction(function () use ($holder, $admin): array {
            $account = $this->portalAccount($holder);
            $password = $this->temporaryPassword();

            $account->forceFill(['password' => $password, 'must_change_password' => true])->save();
            $account->tokens()->delete();

            $this->log($holder, $admin, 'ShareHolder.password_reset', ['employee_id' => $account->id, 'must_change_password' => true, 'tokens_revoked' => true]);

            return ['login' => $account->phone, 'temporary_password' => $password];
        });
    }

    /**
     * Activate or deactivate a shareholder's portal login (employee status active / blocked; blocking signs it out).
     *
     * @throws ValidationException
     */
    public function setLoginActive(ShareHolder $holder, bool $active, Employee $admin): Employee
    {
        return DB::transaction(function () use ($holder, $active, $admin): Employee {
            $account = $this->portalAccount($holder);
            $before = $account->status;

            $account->update(['status' => $active ? 'active' : 'blocked']);
            if (! $active) {
                $account->tokens()->delete();
            }

            $this->log($holder, $admin, $active ? 'ShareHolder.login_activated' : 'ShareHolder.login_deactivated', ['employee_id' => $account->id, 'status_before' => $before, 'status' => $account->status]);

            return $account;
        });
    }

    /**
     * Account overview of the company's shareholders.
     *
     * @return array{total: int, linked: int, not_linked: int, missing_phone: int, invalid_phone: int, missing_email: int, phone_conflicts: int, must_change_password: int, eligible: int}
     */
    public function overview(int $companyId): array
    {
        $rows = $this->rows($companyId);

        return [
            'total' => $rows->count(),
            'linked' => $rows->where('linked', true)->count(),
            'not_linked' => $rows->where('linked', false)->count(),
            'missing_phone' => $rows->where('missing_phone', true)->count(),
            'invalid_phone' => $rows->where('linked', false)->where('missing_phone', false)->where('valid_phone', false)->count(),
            'missing_email' => $rows->where('missing_email', true)->count(),
            'phone_conflicts' => $rows->where('linked', false)->whereNotNull('conflict')->count(),
            'must_change_password' => $rows->where('must_change_password', true)->count(),
            'eligible' => $rows->where('eligible', true)->count(),
        ];
    }

    /**
     * One row per shareholder with its login status and what "Generate accounts" would do.
     *
     * @return Collection<int, array{id: int, name: string, mobile: string|null, email: string|null, linked: bool, account_id: int|null, account_type: string|null, account_status: string|null, must_change_password: bool, missing_phone: bool, valid_phone: bool, missing_email: bool, conflict: string|null, will: string|null, eligible: bool}>
     */
    public function rows(int $companyId): Collection
    {
        $holders = ShareHolder::where('company_id', $companyId)->with('account')->orderBy('id')->get();
        $phones = $holders->whereNull('employee_id')->pluck('mobile')->filter()->map(fn ($phone): string => trim((string) $phone))->unique()->values();
        $employees = Employee::whereIn('phone', $phones)->get()->keyBy('phone');
        $linkedHolders = ShareHolder::whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');

        return $holders->map(function (ShareHolder $holder) use ($employees, $linkedHolders, $companyId): array {
            $mobile = trim((string) $holder->mobile);
            $valid = preg_match(self::PHONE_PATTERN, $mobile) === 1;
            $conflict = null;
            $will = null;

            if ($holder->employee_id === null && $valid) {
                $existing = $employees->get($mobile);
                if ($existing === null) {
                    $will = self::OUTCOME_CREATED;
                } elseif ((int) $existing->company_id !== $companyId) {
                    $conflict = 'The phone belongs to a login of another company.';
                } elseif ($linkedHolders->has($existing->id)) {
                    $conflict = "The phone already belongs to shareholder {$linkedHolders[$existing->id]->full_name}'s login.";
                } else {
                    $will = $existing->isShareholderAccount() ? self::OUTCOME_CREATED : self::OUTCOME_LINKED;
                    if ($will === self::OUTCOME_LINKED) {
                        $conflict = "The phone belongs to staff member {$existing->full_name}; that login will be linked.";
                    }
                }
            }

            return [
                'id' => $holder->id,
                'name' => $holder->full_name,
                'mobile' => $holder->mobile,
                'email' => $holder->email,
                'linked' => $holder->employee_id !== null,
                'account_id' => $holder->employee_id,
                'account_type' => $holder->account?->account_type,
                'account_status' => $holder->account?->status,
                'must_change_password' => (bool) $holder->account?->must_change_password,
                'missing_phone' => $mobile === '',
                'valid_phone' => $valid,
                'missing_email' => blank($holder->email),
                'conflict' => $conflict,
                'will' => $will,
                'eligible' => $holder->employee_id === null && $will !== null,
            ];
        })->values();
    }

    /**
     * Generate logins for the selected (or all) unlinked shareholders with a valid phone. Each shareholder is its own
     * transaction; refused ones are reported and skipped.
     *
     * @param  list<int>|null  $shareHolderIds
     * @return array{created: list<array{share_holder_id: int, name: string, login: string, temporary_password: string}>, linked: list<array{share_holder_id: int, name: string, login: string, employee: string}>, skipped: list<array{share_holder_id: int, name: string, reason: string}>}
     */
    public function generate(int $companyId, ?array $shareHolderIds, Employee $admin): array
    {
        $result = ['created' => [], 'linked' => [], 'skipped' => []];

        $holders = ShareHolder::where('company_id', $companyId)->whereNull('employee_id')
            ->when($shareHolderIds !== null, fn ($query) => $query->whereIn('id', $shareHolderIds))
            ->orderBy('id')->get();

        foreach ($holders as $holder) {
            if (preg_match(self::PHONE_PATTERN, trim((string) $holder->mobile)) !== 1) {
                $result['skipped'][] = ['share_holder_id' => $holder->id, 'name' => $holder->full_name, 'reason' => 'Missing or invalid phone number.'];

                continue;
            }

            try {
                $done = $this->provision($holder, $admin);
            } catch (ValidationException $exception) {
                $result['skipped'][] = ['share_holder_id' => $holder->id, 'name' => $holder->full_name, 'reason' => collect($exception->errors())->flatten()->first() ?? $exception->getMessage()];

                continue;
            }

            if ($done['credentials'] !== null) {
                $result['created'][] = ['share_holder_id' => $holder->id, 'name' => $holder->full_name] + $done['credentials'];
            } else {
                $result['linked'][] = ['share_holder_id' => $holder->id, 'name' => $holder->full_name, 'login' => $done['account']->phone, 'employee' => $done['account']->full_name];
            }
        }

        return $result;
    }

    /**
     * Login summary of one shareholder for admin lists.
     *
     * @return array{linked: bool, account_id: int|null, account_type: string|null, status: string|null, must_change_password: bool, login: string|null}
     */
    public function loginSummary(ShareHolder $holder): array
    {
        $account = $holder->employee_id === null ? null : $holder->account;

        return [
            'linked' => $account !== null,
            'account_id' => $account?->id,
            'account_type' => $account?->account_type,
            'status' => $account?->status,
            'must_change_password' => (bool) $account?->must_change_password,
            'login' => $account?->phone,
        ];
    }

    public function temporaryPassword(): string
    {
        return Str::password(12);
    }

    /**
     * The company's system role `shareholder` (created with the portal permissions when missing).
     */
    public function shareholderRole(int $companyId): Role
    {
        $role = Role::firstOrCreate(['company_id' => $companyId, 'key' => self::ROLE_KEY], ['name' => 'Shareholder', 'is_system' => true]);
        foreach ($this->access->shareholderPortalPermissions() as $permission) {
            $role->permissions()->firstOrCreate(['permission' => $permission]);
        }

        return $role;
    }

    /**
     * @param  array<string, mixed>  $names  first_name, middle_name, last_name, gender, date_of_birth for a new account
     * @return array{employee: Employee, outcome: string, credentials: array{login: string, temporary_password: string}|null}
     *
     * @throws ValidationException
     */
    private function resolveAccount(int $companyId, string $mobile, ?string $email, ?ShareHolder $holder, array $names): array
    {
        $mobile = trim($mobile);
        $this->assertValidPhone($mobile);

        $existing = Employee::where('phone', $mobile)->lockForUpdate()->first();
        if ($existing !== null) {
            $linked = ShareHolder::where('employee_id', $existing->id)->when($holder !== null, fn ($query) => $query->whereKeyNot($holder->id))->first();
            if ((int) $existing->company_id !== $companyId || $linked !== null) {
                throw ValidationException::withMessages(['share_mobile' => $this->phoneTakenMessage($existing, $companyId)]);
            }

            if (! $existing->isShareholderAccount()) {
                return ['employee' => $existing, 'outcome' => self::OUTCOME_LINKED, 'credentials' => null];
            }

            // A portal login left without its shareholder (e.g. the shareholder was deleted): reuse it with a new password.
            $password = $this->temporaryPassword();
            $existing->forceFill(['password' => $password, 'must_change_password' => true, 'status' => 'active', 'email' => $email ?? $existing->email])->save();
            $existing->tokens()->delete();

            return ['employee' => $existing, 'outcome' => self::OUTCOME_CREATED, 'credentials' => ['login' => $existing->phone, 'temporary_password' => $password]];
        }

        if (filled($email)) {
            $emailOwner = ShareHolder::where('company_id', $companyId)->whereNotNull('employee_id')
                ->when($holder !== null, fn ($query) => $query->whereKeyNot($holder->id))
                ->whereHas('account', fn ($query) => $query->where('email', $email)->where('account_type', Employee::ACCOUNT_SHAREHOLDER))
                ->first();
            if ($emailOwner !== null) {
                throw ValidationException::withMessages(['share_email' => "This email already belongs to shareholder {$emailOwner->full_name}'s login."]);
            }
        }

        $password = $this->temporaryPassword();
        $employee = Employee::create([
            'company_id' => $companyId,
            'branch_id' => null,
            'zone_id' => null,
            'role_id' => $this->shareholderRole($companyId)->id,
            'first_name' => (string) ($names['first_name'] ?? 'SHAREHOLDER'),
            'middle_name' => $names['middle_name'] ?? null,
            'last_name' => $names['last_name'] ?? null,
            'phone' => $mobile,
            'email' => $email,
            'gender' => $names['gender'] ?? null,
            'date_of_birth' => $names['date_of_birth'] ?? null,
            'position' => 'employee',
            'status' => 'active',
            'account_type' => Employee::ACCOUNT_SHAREHOLDER,
            'must_change_password' => true,
            'password' => $password,
        ]);

        return ['employee' => $employee, 'outcome' => self::OUTCOME_CREATED, 'credentials' => ['login' => $employee->phone, 'temporary_password' => $password]];
    }

    /**
     * @throws ValidationException
     */
    private function assertValidPhone(string $mobile): void
    {
        if (preg_match(self::PHONE_PATTERN, $mobile) !== 1) {
            throw ValidationException::withMessages(['share_mobile' => 'The phone number must be 9 to 15 digits: it is the shareholder\'s login.']);
        }
    }

    private function phoneTakenMessage(Employee $owner, int $companyId): string
    {
        if ((int) $owner->company_id !== $companyId) {
            return 'This phone already belongs to another login account.';
        }

        $holder = ShareHolder::where('employee_id', $owner->id)->first();

        return $holder !== null
            ? "This phone already belongs to shareholder {$holder->full_name}'s login."
            : "This phone already belongs to the login of {$owner->full_name}.";
    }

    /**
     * @throws ValidationException
     */
    private function portalAccount(ShareHolder $holder): Employee
    {
        $account = $holder->employee_id === null ? null : Employee::whereKey($holder->employee_id)->lockForUpdate()->first();

        if ($account === null) {
            throw ValidationException::withMessages(['share_holder' => "{$holder->full_name} has no login account yet."]);
        }
        if (! $account->isShareholderAccount()) {
            throw ValidationException::withMessages(['share_holder' => "{$holder->full_name} signs in with a staff account; manage it from HRM."]);
        }

        return $account;
    }

    private function audit(ShareHolder $holder, Employee $admin, string $outcome): void
    {
        $this->log($holder, $admin, $outcome === self::OUTCOME_LINKED ? 'ShareHolder.account_linked' : 'ShareHolder.account_created', [
            'employee_id' => $holder->employee_id,
            'outcome' => $outcome,
            'must_change_password' => $outcome === self::OUTCOME_CREATED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $after  never contains a password
     */
    private function log(ShareHolder $holder, Employee $admin, string $action, array $after): void
    {
        AuditLog::create([
            'company_id' => $holder->company_id,
            'employee_id' => $admin->id,
            'action' => $action,
            'auditable_type' => $holder->getMorphClass(),
            'auditable_id' => $holder->id,
            'before' => null,
            'after' => $after,
            'ip_address' => request()?->ip(),
        ]);
    }
}
