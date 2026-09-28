<?php

namespace Tests\Feature\Api\Hrm;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use App\Services\Hrm\EmployeeNumberGenerator;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Unique Empl/IDs (MK-<3 digit sequence><year>): highest issued sequence + 1 under a lock, never reused, enforced by a
 * unique index per company.
 */
class EmployeeNumberTest extends TestCase
{
    use RefreshDatabase;

    private Employee $admin;

    private string $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        $this->year = now()->format('Y');
    }

    private function register(string $phone): string
    {
        $response = $this->postJson('/api/v1/hrm/staff', [
            'empl_name' => 'Devbug3', 'emp_mname' => 'Test', 'emp_lname' => $phone, 'empl_no' => $phone,
            'date_birth' => '1995-01-01', 'empl_email' => "{$phone}@example.com", 'blanch_id' => $this->admin->branch_id,
            'position_id' => 'employee', 'role_id' => $this->admin->company->roles()->where('key', 'loan_officer')->value('id'),
        ])->assertCreated();

        return (string) $response->json('data.employee_number');
    }

    private function existing(string $number, array $attributes = []): Employee
    {
        return Employee::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'employee_number' => $number] + $attributes);
    }

    public function test_normal_registrations_get_unique_consecutive_numbers(): void
    {
        $numbers = [$this->register('0711000001'), $this->register('0711000002'), $this->register('0711000003')];

        $this->assertSame(["MK-001{$this->year}", "MK-002{$this->year}", "MK-003{$this->year}"], $numbers);
        $this->assertSame(3, Employee::whereIn('employee_number', $numbers)->distinct()->count('employee_number'));
    }

    public function test_numbers_follow_the_highest_existing_sequence_of_every_status_and_skip_the_demo_block(): void
    {
        $this->existing('MK-0402025', ['status' => 'rejected']);
        $this->existing('MK-0412026', ['status' => 'blocked']);
        $this->existing('MK-0072024');
        $this->existing('MK-9012024');

        // Registrations count used to be 5 → MK-006…, now the highest sequence (41) + 1.
        $this->assertSame("MK-042{$this->year}", $this->register('0711000001'));
    }

    public function test_deleting_staff_members_does_not_free_their_numbers(): void
    {
        $first = $this->register('0711000001');
        $second = $this->register('0711000002');
        $third = $this->register('0711000003');

        // Delete the two highest numbers: neither a headcount nor a max-of-remaining rule may hand them out again.
        foreach ([$second, $third] as $number) {
            $this->deleteJson('/api/v1/hrm/staff/'.Employee::where('employee_number', $number)->value('id'))->assertOk();
            $this->assertDatabaseMissing('employees', ['employee_number' => $number]);
        }

        $next = $this->register('0711000004');

        $this->assertSame("MK-001{$this->year}", $first);
        $this->assertSame("MK-004{$this->year}", $next);
        $this->assertNotContains($next, [$first, $second, $third]);
    }

    public function test_existing_numbers_are_never_renumbered(): void
    {
        $old = $this->existing('MK-0102024');
        $gap = $this->existing('MK-0252024');

        $this->register('0711000001');

        $this->assertSame('MK-0102024', $old->fresh()->employee_number);
        $this->assertSame('MK-0252024', $gap->fresh()->employee_number);
        $this->assertSame(['MK-0102024', 'MK-0252024', "MK-026{$this->year}"], Employee::whereNotNull('employee_number')->orderBy('employee_number')->pluck('employee_number')->all());
    }

    public function test_the_database_rejects_a_duplicate_number_in_the_same_company(): void
    {
        $this->existing('MK-0012026');

        $other = Company::factory()->create();
        Employee::factory()->create(['company_id' => $other->id, 'branch_id' => Branch::factory()->create(['company_id' => $other->id])->id, 'employee_number' => 'MK-0012026']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->existing('MK-0012026');
    }

    public function test_the_generator_skips_a_number_that_already_exists(): void
    {
        $generator = app(EmployeeNumberGenerator::class);
        DB::table('employee_number_sequences')->insert(['company_id' => $this->admin->company_id, 'last_sequence' => 900, 'created_at' => now(), 'updated_at' => now()]);
        // A reserved demo login number does not move the sequence, but it is never handed out again.
        $this->existing('MK-9012024');

        $this->assertSame('MK-9022024', $generator->next($this->admin->company_id, CarbonImmutable::parse('2024-05-01')));
        $this->assertSame(902, DB::table('employee_number_sequences')->where('company_id', $this->admin->company_id)->value('last_sequence'));
        $this->assertSame(3, $generator->sequenceOf('MK-0032026'));
        $this->assertSame(1000, $generator->sequenceOf('MK-10002026'));
        $this->assertNull($generator->sequenceOf('EMP-1'));
    }

    public function test_generations_in_separate_transactions_never_hand_out_the_same_number(): void
    {
        $generator = app(EmployeeNumberGenerator::class);

        $first = DB::transaction(fn (): string => $generator->next($this->admin->company_id));
        $second = DB::transaction(fn (): string => $generator->next($this->admin->company_id));
        $this->assertSame(["MK-001{$this->year}", "MK-002{$this->year}"], [$first, $second]);

        // A reserved number that was consumed by another registration's transaction moves the next one on.
        DB::transaction(function () use ($generator): void {
            $this->existing($generator->next($this->admin->company_id));
        });
        $this->assertSame("MK-004{$this->year}", $generator->next($this->admin->company_id));

        // The counter row is read FOR UPDATE, so a concurrent registration waits for the commit instead of reading it.
        DB::enableQueryLog();
        $generator->next($this->admin->company_id);
        $this->assertTrue(collect(DB::getQueryLog())->contains(fn (array $query): bool => str_contains($query['query'], 'employee_number_sequences') && str_contains($query['query'], 'for update')));
    }

    public function test_a_collision_at_insert_time_is_retried_once_with_the_next_number(): void
    {
        $taken = $this->existing("MK-001{$this->year}");
        DB::table('employee_number_sequences')->insert(['company_id' => $this->admin->company_id, 'last_sequence' => 0, 'created_at' => now(), 'updated_at' => now()]);

        // The first reservation hands out a number a concurrent registration has just committed.
        $this->app->instance(EmployeeNumberGenerator::class, new class extends EmployeeNumberGenerator
        {
            public int $calls = 0;

            public function next(int $companyId, ?CarbonInterface $at = null): string
            {
                return ++$this->calls === 1 ? 'MK-001'.now()->format('Y') : parent::next($companyId, $at);
            }
        });

        $number = $this->register('0711000001');

        $this->assertSame(2, app(EmployeeNumberGenerator::class)->calls);
        $this->assertSame("MK-002{$this->year}", $number);
        $this->assertSame([$taken->id], Employee::where('employee_number', "MK-001{$this->year}")->pluck('id')->all());
    }
}
