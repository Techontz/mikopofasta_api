<?php

namespace Tests\Feature\Api\Messages;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Zone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagesApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<string, Employee>
     */
    private array $staff = [];

    /**
     * Zone with two branches: A (manager, officer, teller) and B (manager, officer), a zone manager,
     * plus HQ finance and a second zone with its own branch manager.
     */
    private function hierarchy(): Employee
    {
        $admin = $this->signInAdmin();
        $zone = Zone::create(['company_id' => $admin->company_id, 'name' => 'KANDA YA ZIWA']);
        $otherZone = Zone::create(['company_id' => $admin->company_id, 'name' => 'KANDA YA PWANI']);
        $branchA = $admin->branch;
        $branchA->update(['zone_id' => $zone->id]);
        $branchB = Branch::factory()->create(['company_id' => $admin->company_id, 'zone_id' => $zone->id]);
        $branchC = Branch::factory()->create(['company_id' => $admin->company_id, 'zone_id' => $otherZone->id]);

        $make = fn (string $role, Branch $branch, ?int $zoneId = null): Employee => Employee::factory()->create([
            'company_id' => $admin->company_id,
            'branch_id' => $branch->id,
            'zone_id' => $zoneId,
            'role_id' => $admin->company->roles()->where('key', $role)->value('id'),
        ]);

        $this->staff = [
            'admin' => $admin,
            'managerA' => $make('branch_manager', $branchA),
            'officerA' => $make('loan_officer', $branchA),
            'tellerA' => $make('teller', $branchA),
            'managerB' => $make('branch_manager', $branchB),
            'officerB' => $make('loan_officer', $branchB),
            'zoneManager' => $make('zone_manager', $branchA, $zone->id),
            'finance' => $make('finance', $branchA),
            'managerC' => $make('branch_manager', $branchC),
        ];

        return $admin;
    }

    /**
     * @return list<int>
     */
    private function contactIds(Employee $employee): array
    {
        $this->actingAs($employee);

        return collect($this->getJson('/api/v1/messages/contacts')->assertOk()->json('data'))->pluck('value')->map(fn ($id): int => (int) $id)->sort()->values()->all();
    }

    public function test_hierarchy_decides_who_can_be_messaged(): void
    {
        $this->hierarchy();
        $s = $this->staff;
        $ids = fn (string ...$keys): array => collect($keys)->map(fn (string $key): int => $s[$key]->id)->sort()->values()->all();

        $this->assertSame($ids('managerA'), $this->contactIds($s['officerA']));
        $this->assertSame($ids('officerA', 'tellerA', 'zoneManager'), $this->contactIds($s['managerA']));
        $this->assertSame($ids('admin', 'managerA', 'officerA', 'tellerA', 'managerB', 'officerB', 'finance'), $this->contactIds($s['zoneManager']));
        $this->assertCount(8, $this->contactIds($s['finance']));
        // Zone without a zone manager: its branch manager reports to company admins.
        $this->assertSame($ids('admin'), $this->contactIds($s['managerC']));
    }

    public function test_employee_messages_head_and_head_replies_with_unread_counts(): void
    {
        $this->hierarchy();
        $s = $this->staff;

        $this->actingAs($s['officerA']);
        $this->postJson('/api/v1/messages/conversations', ['employee_id' => $s['officerB']->id, 'body' => 'Habari'])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $conversationId = $this->postJson('/api/v1/messages/conversations', ['employee_id' => $s['managerA']->id, 'body' => 'Mteja ameomba mkopo mkubwa'])
            ->assertCreated()->assertJsonPath('message', 'Message sent successfully')->json('data.id');

        $this->actingAs($s['managerA']);
        $this->getJson('/api/v1/messages/unread')->assertOk()->assertJsonPath('data.count', 1);
        $this->getJson('/api/v1/messages/conversations')->assertOk()
            ->assertJsonPath('data.0.title', $s['officerA']->full_name)
            ->assertJsonPath('data.0.unread_count', 1);

        $thread = $this->getJson("/api/v1/messages/conversations/{$conversationId}")->assertOk()->assertJsonCount(1, 'data.messages');
        $this->getJson('/api/v1/messages/unread')->assertJsonPath('data.count', 0);

        $this->postJson("/api/v1/messages/conversations/{$conversationId}/messages", ['body' => 'Sawa, nitaangalia'])->assertCreated();
        $this->postJson('/api/v1/messages/conversations', ['employee_id' => $s['officerA']->id, 'body' => 'Tuma fomu'])
            ->assertCreated()->assertJsonPath('data.id', $conversationId);

        $this->actingAs($s['officerA']);
        $this->getJson("/api/v1/messages/conversations/{$conversationId}?after_id=".$thread->json('data.messages.0.id'))
            ->assertOk()->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.0.mine', false);

        $this->actingAs($s['tellerA']);
        $this->getJson("/api/v1/messages/conversations/{$conversationId}")->assertForbidden();
        $this->postJson("/api/v1/messages/conversations/{$conversationId}/messages", ['body' => 'x'])->assertForbidden();
    }

    public function test_heads_create_groups_and_broadcasts_within_their_scope(): void
    {
        $this->hierarchy();
        $s = $this->staff;

        $this->actingAs($s['officerA']);
        $this->postJson('/api/v1/messages/groups', ['name' => 'Wadau', 'employee_ids' => [$s['managerA']->id]])->assertUnprocessable();
        $this->postJson('/api/v1/messages/broadcasts', ['audience' => 'branch:'.$s['officerA']->branch_id, 'body' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('audience');

        $this->actingAs($s['zoneManager']);
        $this->postJson('/api/v1/messages/groups', ['name' => 'Mameneja', 'employee_ids' => [$s['managerA']->id, $s['managerC']->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('employee_ids');
        $groupId = $this->postJson('/api/v1/messages/groups', ['name' => 'Mameneja', 'employee_ids' => [$s['managerA']->id, $s['managerB']->id], 'body' => 'Kikao kesho'])
            ->assertCreated()->json('data.id');

        $zoneId = $s['zoneManager']->zone_id;
        $this->postJson('/api/v1/messages/broadcasts', ['audience' => 'zone:'.$s['managerC']->branch->zone_id, 'body' => 'x'])->assertUnprocessable();
        $broadcastId = $this->postJson('/api/v1/messages/broadcasts', ['audience' => "zone:{$zoneId}", 'body' => 'Tangazo kwa kanda nzima'])
            ->assertCreated()->json('data.id');

        $this->actingAs($s['officerB']);
        $this->getJson('/api/v1/messages/unread')->assertJsonPath('data.count', 1);
        $this->getJson("/api/v1/messages/conversations/{$broadcastId}")->assertOk()->assertJsonPath('data.conversation.can_post', false);
        $this->postJson("/api/v1/messages/conversations/{$broadcastId}/messages", ['body' => 'Asante'])->assertUnprocessable();

        $this->actingAs($s['managerB']);
        $this->postJson("/api/v1/messages/conversations/{$groupId}/messages", ['body' => 'Nitahudhuria'])->assertCreated();

        $this->actingAs($s['managerC']);
        $this->getJson('/api/v1/messages/conversations')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_validation_and_permission(): void
    {
        $admin = $this->signInAdmin();

        $this->postJson('/api/v1/messages/conversations', [])->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'body']);
        $this->postJson('/api/v1/messages/broadcasts', ['audience' => 'everyone'])->assertUnprocessable()->assertJsonValidationErrors(['audience', 'body']);

        $role = $admin->company->roles()->create(['key' => 'no_chat', 'name' => 'No chat']);
        $this->actingAs(Employee::factory()->create(['company_id' => $admin->company_id, 'branch_id' => $admin->branch_id, 'role_id' => $role->id]));
        $this->getJson('/api/v1/messages/conversations')->assertForbidden();
    }
}
