<?php

namespace Tests\Feature\Api;

use App\Models\Employee;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeders_create_one_working_login_per_role_with_the_configured_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);

        $accounts = ['super_admin' => ['phone' => config('demo.admin_phone'), 'password' => config('demo.admin_password'), 'placement' => 'hq']]
            + config('demo.accounts');

        $this->assertEqualsCanonicalizing(
            ['super_admin', 'admin', 'teller', 'finance', 'zone_manager', 'branch_manager', 'loan_officer', 'credit_officer', 'hr'],
            array_keys($accounts),
        );

        foreach ($accounts as $roleKey => $account) {
            $this->assertSame(1, Employee::where('phone', $account['phone'])->count(), "{$roleKey} phone must be unique");

            $response = $this->postJson('/api/v1/auth/login', ['phone' => $account['phone'], 'password' => $account['password']])
                ->assertOk()
                ->assertJsonPath('user.role.key', $roleKey)
                ->assertJsonPath('user.status', 'active');

            match ($account['placement']) {
                'branch' => $response->assertJsonPath('user.branch.name', config('demo.branch'))->assertJsonCount(1, 'user.branch_ids'),
                'zone' => $response->assertJsonPath('user.zone.name', config('demo.zone')),
                default => $response->assertJsonPath('user.branch_ids', null),
            };

            $this->app['auth']->forgetGuards();
            $this->withToken($response->json('token'))->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.role.key', $roleKey);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_seeding_master_data_again_keeps_the_demo_logins_stable(): void
    {
        $this->seed(DatabaseSeeder::class);
        $ids = Employee::whereIn('phone', array_column(config('demo.accounts'), 'phone'))->pluck('id', 'phone');

        $this->seed(MasterDataSeeder::class);

        $this->assertEquals($ids, Employee::whereIn('phone', array_column(config('demo.accounts'), 'phone'))->pluck('id', 'phone'));
        $this->postJson('/api/v1/auth/login', ['phone' => config('demo.accounts.teller.phone'), 'password' => config('demo.accounts.teller.password')])->assertOk();
    }
}
