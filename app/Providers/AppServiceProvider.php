<?php

namespace App\Providers;

use App\Models\Employee;
use App\Services\AccessControl;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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
        Gate::before(function (Employee $employee, string $ability): ?bool {
            if (! array_key_exists($ability, config('permissions.permissions'))) {
                return null;
            }

            return app(AccessControl::class)->can($employee, $ability);
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->string('phone')->lower()->toString().'|'.$request->ip()));
    }
}
