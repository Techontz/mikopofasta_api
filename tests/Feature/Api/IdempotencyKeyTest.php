<?php

namespace Tests\Feature\Api;

use App\Enums\Account;
use App\Models\Employee;
use App\Models\FloatTransfer;
use App\Models\IdempotentRequest;
use App\Models\JournalEntry;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

class IdempotencyKeyTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        Sanctum::actingAs($this->admin);
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Company, 1000000);
    }

    public function test_a_retry_with_the_same_key_replays_the_response_and_posts_one_journal(): void
    {
        $body = ['amount' => 1000, 'from_account' => Account::Company->value];
        $entries = JournalEntry::count();

        $first = $this->postJson('/api/v1/capital/floats', $body, ['Idempotency-Key' => 'float-key-0001'])->assertCreated();
        $first->assertHeaderMissing('Idempotent-Replayed');

        $replay = $this->postJson('/api/v1/capital/floats', $body, ['Idempotency-Key' => 'float-key-0001'])
            ->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        $this->assertSame($first->json(), $replay->json());
        $this->assertSame(1, FloatTransfer::count());
        $this->assertSame($entries, JournalEntry::count(), 'rule 6: the float is pending until another user approves it');

        $approver = $this->secondApprover($this->admin);
        Sanctum::actingAs($approver);
        $uri = "/api/v1/capital/floats/{$first->json('data.id')}/approve";
        $this->postJson($uri, [], ['Idempotency-Key' => 'approve-key-0001'])->assertOk();
        $this->postJson($uri, [], ['Idempotency-Key' => 'approve-key-0001'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
        Sanctum::actingAs($this->admin);
        $this->assertSame($entries + 1, JournalEntry::count());
        $this->assertSame(1000.0, app(Ledger::class)->balance($this->admin->company_id, Account::Principal));
        $this->assertDatabaseHas('idempotent_requests', ['employee_id' => $this->admin->id, 'key' => 'float-key-0001', 'status' => 'completed', 'response_status' => 201]);
    }

    public function test_the_same_key_for_a_different_request_is_rejected(): void
    {
        $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value], ['Idempotency-Key' => 'float-key-0002'])->assertCreated();

        $this->postJson('/api/v1/capital/floats', ['amount' => 2000, 'from_account' => Account::Company->value], ['Idempotency-Key' => 'float-key-0002'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This request key was already used for a different request.')
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');

        $this->assertSame(1, FloatTransfer::count());
    }

    public function test_a_key_still_being_processed_returns_conflict(): void
    {
        $body = ['amount' => 1000, 'from_account' => Account::Company->value];
        $this->postJson('/api/v1/capital/floats', $body, ['Idempotency-Key' => 'float-key-0003'])->assertCreated();
        IdempotentRequest::query()->where('key', 'float-key-0003')->update(['status' => IdempotentRequest::STATUS_PROCESSING, 'response_status' => null, 'response_body' => null]);

        $this->postJson('/api/v1/capital/floats', $body, ['Idempotency-Key' => 'float-key-0003'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This request is already being processed.')
            ->assertJsonPath('error_code', 'CONFLICT');

        $this->assertSame(1, FloatTransfer::count());
    }

    public function test_failed_requests_are_not_stored_so_a_corrected_retry_proceeds(): void
    {
        $this->postJson('/api/v1/capital/floats', ['amount' => 0, 'from_account' => Account::Company->value], ['Idempotency-Key' => 'float-key-0004'])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertDatabaseMissing('idempotent_requests', ['key' => 'float-key-0004']);

        $this->postJson('/api/v1/capital/floats', ['amount' => 0, 'from_account' => Account::Company->value], ['Idempotency-Key' => 'float-key-0004'])
            ->assertUnprocessable();

        $this->postJson('/api/v1/capital/floats', ['amount' => 4000, 'from_account' => Account::Company->value], ['Idempotency-Key' => 'float-key-0004'])
            ->assertCreated()->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame(1, FloatTransfer::count());
    }

    public function test_keys_are_scoped_per_employee(): void
    {
        $body = ['amount' => 1000, 'from_account' => Account::Company->value];
        $this->postJson('/api/v1/capital/floats', $body, ['Idempotency-Key' => 'shared-key-0005'])->assertCreated();

        $other = Employee::factory()->admin()->create([
            'company_id' => $this->admin->company_id,
            'branch_id' => $this->admin->branch_id,
            'role_id' => $this->admin->role_id,
        ]);
        Sanctum::actingAs($other);

        $this->postJson('/api/v1/capital/floats', $body, ['Idempotency-Key' => 'shared-key-0005'])
            ->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        $this->assertSame(2, FloatTransfer::count());
    }

    public function test_get_requests_requests_without_a_key_and_invalid_keys(): void
    {
        $this->getJson('/api/v1/capital/floats', ['Idempotency-Key' => 'read-key-0006'])->assertOk();
        $this->assertDatabaseCount('idempotent_requests', 0);

        $body = ['amount' => 1000, 'from_account' => Account::Company->value];
        $this->postJson('/api/v1/capital/floats', $body)->assertCreated();
        $this->postJson('/api/v1/capital/floats', $body)->assertCreated();
        $this->assertSame(2, FloatTransfer::count());

        $this->postJson('/api/v1/capital/floats', $body, ['Idempotency-Key' => 'bad key!'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(2, FloatTransfer::count());
        $this->assertDatabaseCount('idempotent_requests', 0);
    }

    public function test_prune_command_removes_old_requests(): void
    {
        $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value], ['Idempotency-Key' => 'float-key-0007'])->assertCreated();
        IdempotentRequest::query()->update(['created_at' => now()->subDays(8)]);
        $this->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value], ['Idempotency-Key' => 'float-key-0008'])->assertCreated();

        $this->artisan('idempotency:prune')->assertSuccessful();

        $this->assertDatabaseMissing('idempotent_requests', ['key' => 'float-key-0007']);
        $this->assertDatabaseHas('idempotent_requests', ['key' => 'float-key-0008']);
    }
}
