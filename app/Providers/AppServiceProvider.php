<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Auth\Policies\RolePolicy;
use App\Domain\Auth\Policies\UserPolicy;
use App\Domain\Customers\Policies\CustomerCategoryPolicy;
use App\Domain\Customers\Policies\CustomerPolicy;
use App\Domain\Customers\Policies\GroupPolicy;
use App\Domain\Hr\Policies\CommissionPolicy;
use App\Domain\Hr\Policies\PayrollPolicy;
use App\Domain\Hr\Policies\StaffPolicy;
use App\Domain\Ledger\Policies\LedgerPolicy;
use App\Domain\Loans\Policies\LoanPolicy;
use App\Domain\Loans\Policies\LoanProductPolicy;
use App\Domain\Organization\Policies\BranchPolicy;
use App\Domain\Organization\Policies\CompanyProfilePolicy;
use App\Domain\Organization\Policies\RegionPolicy;
use App\Domain\Organization\Policies\ZonePolicy;
use App\Domain\Repayments\Policies\PaymentPolicy;
use App\Domain\Reports\Policies\ReportPolicy;
use App\Models\Branch;
use App\Models\CommissionPool;
use App\Models\CompanyProfile;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Group;
use App\Models\JournalEntry;
use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Region;
use App\Models\Role;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\Zone;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureModels();
        $this->configureDates();
        $this->configureCommands();
        $this->configureUrls();
        $this->configureNumbers();
        $this->configurePolicies();
        $this->configureRateLimiting();
    }

    /**
     * Policies live under app/Domain/<Module>/Policies rather than app/Policies,
     * so Laravel's convention-based discovery does not find them and they are
     * registered explicitly.
     */
    private function configurePolicies(): void
    {
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(Role::class, RolePolicy::class);

        Gate::policy(Branch::class, BranchPolicy::class);
        Gate::policy(Zone::class, ZonePolicy::class);
        Gate::policy(Region::class, RegionPolicy::class);
        Gate::policy(CompanyProfile::class, CompanyProfilePolicy::class);

        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(CustomerCategory::class, CustomerCategoryPolicy::class);
        Gate::policy(Group::class, GroupPolicy::class);

        Gate::policy(Loan::class, LoanPolicy::class);
        Gate::policy(LoanProduct::class, LoanProductPolicy::class);

        Gate::policy(JournalEntry::class, LedgerPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);

        Gate::policy(StaffProfile::class, StaffPolicy::class);
        Gate::policy(PayrollRun::class, PayrollPolicy::class);
        Gate::policy(CommissionPool::class, CommissionPolicy::class);

        // Reports span every module, so there is no model to bind a policy to.
        Gate::define(ReportPolicy::VIEW_ABILITY, [ReportPolicy::class, 'viewAny']);

        /*
         * Super Admin holds every permission by definition (§14). Granting it
         * here as well means a policy can never accidentally lock out the one
         * role that must always be able to intervene — including on future
         * modules whose policies do not exist yet.
         *
         * Deliberately Gate::before and not a wildcard permission row: this
         * cannot be revoked through the permission matrix.
         */
        Gate::before(static function (User $user, string $ability): ?bool {
            return $user->isSuperAdmin() ? true : null;
        });
    }

    /**
     * Rate limiting (spec §1).
     *
     * Three tiers, because the endpoints have very different threat profiles:
     *
     *  - `auth`           credential submission. Keyed on phone + IP so that
     *                     hammering one account cannot lock out other users
     *                     behind the same NAT, and rotating IPs cannot bypass
     *                     the per-account limit.
     *  - `password-reset` keyed on email + IP. Tighter still, since each
     *                     attempt sends mail to a third party.
     *  - `api`            the authenticated default, keyed per user (falling
     *                     back to IP), generous enough not to interfere with
     *                     normal table paging.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth', static function (Request $request): array {
            $phone = (string) $request->input('phone', '');

            return [
                Limit::perMinute(5)->by('auth:'.$phone.'|'.$request->ip()),
                Limit::perMinute(20)->by('auth-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('password-reset', static function (Request $request): array {
            $email = mb_strtolower((string) $request->input('email', ''));

            return [
                Limit::perMinutes(15, 3)->by('pwreset:'.$email),
                Limit::perMinutes(15, 10)->by('pwreset-ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('api', static function (Request $request): Limit {
            $user = $request->user();

            return $user !== null
                ? Limit::perMinute(120)->by('api:'.$user->getAuthIdentifier())
                : Limit::perMinute(30)->by('api-ip:'.$request->ip());
        });
    }

    /**
     * Strict mode turns three classes of silent bug into loud exceptions.
     * In a double-entry system a silently discarded attribute or an N+1 that
     * quietly returns stale data is a correctness problem, not a performance
     * one — so these are errors in every environment except production, where
     * they are merely reported rather than fatal to a live request.
     */
    private function configureModels(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());
        Model::automaticallyEagerLoadRelationships();
    }

    /**
     * CarbonImmutable prevents the classic accounting bug where a shared date
     * instance is mutated in place while building a schedule or a period range.
     */
    private function configureDates(): void
    {
        Date::use(CarbonImmutable::class);
    }

    /**
     * The ledger is append-only: `migrate:fresh`, `db:wipe` and `migrate --force`
     * must never be reachable in production, however the command is invoked.
     */
    private function configureCommands(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
    }

    private function configureUrls(): void
    {
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }
    }

    private function configureNumbers(): void
    {
        Number::useCurrency('TZS');
    }
}
