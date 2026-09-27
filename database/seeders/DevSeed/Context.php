<?php

namespace Database\Seeders\DevSeed;

use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\ShareHolder;
use Closure;
use RuntimeException;

/**
 * Shared state of one seeding run: the API client, the timeline and natural-key lookups of the records the data set uses.
 */
final class Context
{
    /**
     * @var array<string, Employee>
     */
    private array $actors = [];

    /**
     * True when an earlier run already completed the whole history (its last dated milestone exists): historic
     * maintenance jobs (daily overdue processing for past dates) are then not replayed.
     */
    public bool $historyComplete = false;

    /**
     * @param  Closure(string): void  $log
     */
    public function __construct(
        public readonly Api $api,
        public readonly Timeline $timeline,
        public readonly Company $company,
        public readonly Closure $log,
    ) {}

    public function branch(string $code): Branch
    {
        return Branch::where('company_id', $this->company->id)->where('name', Catalog::BRANCHES[$code][0])->firstOrFail();
    }

    public function branchExists(string $code): bool
    {
        return Branch::where('company_id', $this->company->id)->where('name', Catalog::BRANCHES[$code][0])->exists();
    }

    public static function staffPhone(string $key): string
    {
        $index = array_search($key, array_keys(Catalog::STAFF), true);

        return sprintf('07461000%02d', $index + 1);
    }

    public function staff(string $key): Employee
    {
        return Employee::where('company_id', $this->company->id)->where('phone', self::staffPhone($key))->firstOrFail();
    }

    public function staffExists(string $key): bool
    {
        return Employee::where('company_id', $this->company->id)->where('phone', self::staffPhone($key))->exists();
    }

    /**
     * A demo login (config/demo.php): super_admin, admin, teller, finance, zone_manager, branch_manager, loan_officer, credit_officer, hr.
     */
    public function demo(string $role): Employee
    {
        $phone = $role === 'super_admin' ? config('demo.admin_phone') : config("demo.accounts.{$role}.phone");

        return $this->actors["demo:{$role}"] ??= Employee::where('phone', $phone)->firstOrFail();
    }

    /**
     * The employee who performs a branch role: the DEV staff member of a new branch, the demo account at Kakonko, otherwise
     * the first active employee with that role at the branch.
     */
    public function branchActor(string $branchCode, string $role): Employee
    {
        $key = match ($role) {
            'branch_manager' => "{$branchCode}_BM",
            'loan_officer' => "{$branchCode}_LO",
            'teller' => "{$branchCode}_TL",
            default => throw new RuntimeException("Unknown branch role {$role}"),
        };

        if (array_key_exists($key, Catalog::STAFF)) {
            return $this->staff($key);
        }
        if ($branchCode === 'KK' && in_array($role, ['branch_manager', 'teller'], true)) {
            return $this->demo($role);
        }

        return $this->actors["{$branchCode}:{$role}"] ??= Employee::where('company_id', $this->company->id)
            ->where('branch_id', $this->branch($branchCode)->id)
            ->where('status', 'active')
            ->whereHas('role', fn ($query) => $query->where('key', $role))
            ->orderBy('id')
            ->firstOrFail();
    }

    public function customerType(string $code): CustomerCategory
    {
        return CustomerCategory::where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
    }

    public function category(string $key): LoanCategory
    {
        return LoanCategory::where('company_id', $this->company->id)->where('name', Catalog::LOAN_CATEGORIES[$key]['name'])->firstOrFail();
    }

    public function bank(string $name): BankAccount
    {
        return BankAccount::where('company_id', $this->company->id)->where('name', $name)->firstOrFail();
    }

    public function customer(string $phone): ?Customer
    {
        return Customer::withTrashed()->where('company_id', $this->company->id)->where('phone', $phone)->first();
    }

    public static function marker(string $code): string
    {
        return '['.Catalog::MARKER."-{$code}]";
    }

    public function loan(string $code): ?Loan
    {
        return Loan::where('company_id', $this->company->id)->where('reason', 'like', '%'.self::marker($code))->first();
    }

    public static function shareholderPhone(string $key): string
    {
        return sprintf('07681000%02d', (int) substr($key, 2));
    }

    public function shareholder(string $key): ShareHolder
    {
        return ShareHolder::where('company_id', $this->company->id)->where('mobile', self::shareholderPhone($key))->firstOrFail();
    }

    public function log(string $message): void
    {
        ($this->log)($message);
    }
}
