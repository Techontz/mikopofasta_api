<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Enums\GroupRole;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Group;

/**
 * Groups — the membership rules, not just the endpoints.
 *
 * Each test names the rule it protects, because the value of these is that a
 * later change which quietly drops one fails here rather than in a branch
 * meeting.
 */
beforeEach(function (): void {
    seedCustomerBook();
});

/**
 * A fresh group at the branch with the most customers free to join one.
 *
 * The customer book seeds its own groups, so a test that picks an arbitrary
 * branch can find every candidate already spoken for — which surfaces as a
 * confusing ModelNotFound rather than as the rule under test.
 */
function groupAtBranch(?Branch $branch = null): Group
{
    $branch ??= Branch::query()->findOrFail(spareBranchId());

    return Group::create([
        'name' => 'Wazuri '.fake()->unique()->numberBetween(1, 9999),
        'branch_id' => $branch->id,
        'status' => 'active',
    ]);
}

/** The branch with the most active customers not yet in a group. */
function spareBranchId(): int
{
    return (int) freeCustomers()
        ->get()
        ->groupBy('branch_id')
        ->sortByDesc(fn ($rows) => $rows->count())
        ->keys()
        ->first();
}

/** @return Illuminate\Database\Eloquent\Builder<Customer> */
function freeCustomers()
{
    return Customer::query()
        ->where('status', 'active')
        ->whereDoesntHave('groupMemberships', fn ($q) => $q->where('status', 'active'));
}

/** Distinct customers at a branch, none of them already in a group. */
function activeCustomersAt(Branch $branch, int $count = 1): Illuminate\Support\Collection
{
    $found = freeCustomers()->where('branch_id', $branch->id)->take($count)->get();

    expect($found)->toHaveCount($count, "the seeded book has fewer than {$count} free customers at {$branch->name}");

    return $found;
}

function activeCustomerAt(Branch $branch): Customer
{
    return activeCustomersAt($branch)->first();
}

it('lists groups with member count and outstanding balance', function (): void {
    $group = groupAtBranch();
    actingAsRole(RoleName::LoanOfficer);

    $response = $this->getJson('/api/v1/groups');

    $response->assertOk();

    $row = collect($response->json('data'))->firstWhere('name', $group->name);

    expect($row)->not->toBeNull()
        ->and($row['memberCount'])->toBe(0)
        ->and($row['outstandingBalance'])->toBe('0.00');
});

it('refuses a member from a different branch', function (): void {
    $group = groupAtBranch();
    $elsewhere = Customer::query()->where('branch_id', '!=', $group->branch_id)->firstOrFail();

    actingAsRole(RoleName::LoanOfficer);

    $this->postJson("/api/v1/groups/{$group->id}/members", ['customerId' => $elsewhere->id])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');
});

it('refuses a customer who already belongs to a group', function (): void {
    $group = groupAtBranch();
    $other = groupAtBranch($group->branch);
    $customer = activeCustomerAt($group->branch);

    actingAsRole(RoleName::LoanOfficer);

    $this->postJson("/api/v1/groups/{$group->id}/members", ['customerId' => $customer->id])
        ->assertCreated();

    $this->postJson("/api/v1/groups/{$other->id}/members", ['customerId' => $customer->id])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
});

it('allows only one holder of each office', function (): void {
    $group = groupAtBranch();
    [$first, $second] = activeCustomersAt($group->branch, 2)->all();
    actingAsRole(RoleName::LoanOfficer);

    $this->postJson("/api/v1/groups/{$group->id}/members", [
        'customerId' => $first->id,
        'role' => GroupRole::Treasurer->value,
    ])->assertCreated();

    $this->postJson("/api/v1/groups/{$group->id}/members", [
        'customerId' => $second->id,
        'role' => GroupRole::Treasurer->value,
    ])->assertStatus(409);
});

it('tracks the group leader when the office is filled and vacated', function (): void {
    $group = groupAtBranch();
    $customer = activeCustomerAt($group->branch);
    actingAsRole(RoleName::LoanOfficer);

    $created = $this->postJson("/api/v1/groups/{$group->id}/members", [
        'customerId' => $customer->id,
        'role' => GroupRole::Leader->value,
    ])->assertCreated();

    expect($group->fresh()->leader_customer_id)->toBe($customer->id);

    $memberId = $created->json('data.id');

    // Stepping down clears the pointer rather than leaving it on an ex-leader.
    $this->patchJson("/api/v1/groups/{$group->id}/members/{$memberId}", [
        'customerId' => $customer->id,
        'role' => GroupRole::Member->value,
    ])->assertOk();

    expect($group->fresh()->leader_customer_id)->toBeNull();
});

it('refuses to remove a member who still holds an office', function (): void {
    $group = groupAtBranch();
    $customer = activeCustomerAt($group->branch);
    actingAsRole(RoleName::LoanOfficer);

    $created = $this->postJson("/api/v1/groups/{$group->id}/members", [
        'customerId' => $customer->id,
        'role' => GroupRole::Secretary->value,
    ])->assertCreated();

    $this->deleteJson("/api/v1/groups/{$group->id}/members/{$created->json('data.id')}")
        ->assertStatus(409);
});

it('marks a departing member as left rather than deleting the row', function (): void {
    $group = groupAtBranch();
    $customer = activeCustomerAt($group->branch);
    actingAsRole(RoleName::LoanOfficer);

    $created = $this->postJson("/api/v1/groups/{$group->id}/members", ['customerId' => $customer->id])
        ->assertCreated();

    $this->deleteJson("/api/v1/groups/{$group->id}/members/{$created->json('data.id')}")
        ->assertOk();

    // The history survives — it is evidence for who guaranteed a running loan.
    $this->assertDatabaseHas('group_members', [
        'id' => $created->json('data.id'),
        'status' => 'left',
    ]);
});

it('rejects a duplicate group name within the same branch but allows it across branches', function (): void {
    $group = groupAtBranch();
    $elsewhere = Branch::query()->where('id', '!=', $group->branch_id)->firstOrFail();

    actingAsRole(RoleName::LoanOfficer);

    $this->postJson('/api/v1/groups', ['name' => $group->name, 'branchId' => $group->branch_id])
        ->assertStatus(422)
        ->assertJsonValidationErrors('name');

    $this->postJson('/api/v1/groups', ['name' => $group->name, 'branchId' => $elsewhere->id])
        ->assertCreated();
});

it('denies a role without customers.manage the right to change membership', function (): void {
    $group = groupAtBranch();
    $customer = activeCustomerAt($group->branch);

    // Auditor is read-only across the system.
    actingAsRole(RoleName::Auditor);

    $this->postJson("/api/v1/groups/{$group->id}/members", ['customerId' => $customer->id])
        ->assertForbidden();
});

it('lets a read-only role list groups', function (): void {
    groupAtBranch();
    actingAsRole(RoleName::Auditor);

    $this->getJson('/api/v1/groups')->assertOk();
});
