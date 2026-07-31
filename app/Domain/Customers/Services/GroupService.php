<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Enums\GroupRole;
use App\Domain\Customers\Exceptions\GroupException;
use App\Enums\ActiveStatus;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Loan;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The rules a village banking group runs on.
 *
 * Everything that decides whether a membership change is legal lives here, so
 * the controller stays a translation layer and the same rule holds whether a
 * member is added through the API, a seeder or a future import.
 *
 * Four rules, each with a reason:
 *
 *   1. A member must belong to the group's own branch. A group meets in one
 *      place; a customer served from another branch cannot attend, and their
 *      loan would be collected by an officer who never sees the meeting.
 *   2. One customer, one group. Group liability means the other members stand
 *      behind the loan — a customer in two groups puts two sets of guarantors
 *      on the hook for the same person without either knowing.
 *   3. At most one holder of each office. Enforced in the database as well
 *      (see the migration); checked here so the failure is a clear message
 *      rather than an integrity violation.
 *   4. An officer cannot simply be removed. Demote first, so the group is never
 *      left without a treasurer by accident.
 */
final class GroupService
{
    /**
     * Add a customer to a group.
     *
     * @throws GroupException
     */
    public function addMember(Group $group, Customer $customer, GroupRole $role, ?Carbon $joinedAt = null): GroupMember
    {
        $this->assertSameBranch($group, $customer);
        $this->assertCustomerIsActive($customer);
        $this->assertNotAlreadyGrouped($customer);

        if ($role->isOffice()) {
            $this->assertOfficeVacant($group, $role);
        }

        return DB::transaction(function () use ($group, $customer, $role, $joinedAt): GroupMember {
            $member = $group->members()->create([
                'customer_id' => $customer->id,
                'role' => $role,
                'joined_at' => $joinedAt ?? Carbon::now(),
                'status' => 'active',
            ]);

            // The group's public representative tracks whoever holds the office.
            if ($role === GroupRole::Leader) {
                $group->update(['leader_customer_id' => $customer->id]);
            }

            return $member;
        });
    }

    /**
     * Change a member's office.
     *
     * @throws GroupException
     */
    public function assignRole(GroupMember $member, GroupRole $role): GroupMember
    {
        if ($member->role === $role) {
            return $member;
        }

        if ($role->isOffice()) {
            $this->assertOfficeVacant($member->group, $role, exceptMemberId: $member->id);
        }

        return DB::transaction(function () use ($member, $role): GroupMember {
            $wasLeader = $member->role === GroupRole::Leader;
            $member->update(['role' => $role]);

            $group = $member->group;
            if ($role === GroupRole::Leader) {
                $group->update(['leader_customer_id' => $member->customer_id]);
            } elseif ($wasLeader) {
                // Stepping down clears the representative rather than leaving a
                // stale pointer at someone who no longer holds the office.
                $group->update(['leader_customer_id' => null]);
            }

            return $member->fresh();
        });
    }

    /**
     * Remove a member from a group.
     *
     * The row is marked `left` rather than deleted: the group's history is
     * evidence for who guaranteed a loan that may still be running.
     *
     * @throws GroupException
     */
    public function removeMember(GroupMember $member): void
    {
        if ($member->role->isOffice()) {
            throw GroupException::officerCannotLeave($member->role);
        }

        if ($this->memberHasOpenGroupLoan($member)) {
            throw GroupException::memberHasOpenLoan();
        }

        DB::transaction(function () use ($member): void {
            $member->update(['status' => 'left']);
        });
    }

    /**
     * A group can only be closed once nothing is outstanding against it.
     *
     * @throws GroupException
     */
    public function close(Group $group): void
    {
        if ($this->outstandingBalance($group)->isPositive()) {
            throw GroupException::groupHasOpenLoans();
        }

        DB::transaction(function () use ($group): void {
            $group->update(['status' => ActiveStatus::Inactive]);
        });
    }

    /**
     * What the group still owes, across every open loan booked to it.
     *
     * Derived from the loan schedules each time rather than stored, so it
     * cannot drift from the repayments that produced it.
     */
    public function outstandingBalance(Group $group): Money
    {
        $loans = Loan::query()
            ->where('group_id', $group->id)
            ->with('schedules')
            ->get()
            ->filter(fn (Loan $loan): bool => $loan->isOpen());

        return Money::sum($loans->map(fn (Loan $loan): Money => $loan->outstandingTotal()));
    }

    private function assertSameBranch(Group $group, Customer $customer): void
    {
        if ($customer->branch_id !== $group->branch_id) {
            throw GroupException::branchMismatch();
        }
    }

    private function assertCustomerIsActive(Customer $customer): void
    {
        if ($customer->status !== CustomerStatus::Active) {
            throw GroupException::customerNotActive();
        }
    }

    private function assertNotAlreadyGrouped(Customer $customer): void
    {
        $existing = GroupMember::query()
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->exists();

        if ($existing) {
            throw GroupException::alreadyInAGroup();
        }
    }

    private function assertOfficeVacant(Group $group, GroupRole $role, ?int $exceptMemberId = null): void
    {
        $held = $group->members()
            ->where('role', $role->value)
            ->where('status', 'active')
            ->when($exceptMemberId !== null, fn ($q) => $q->whereKeyNot($exceptMemberId))
            ->exists();

        if ($held) {
            throw GroupException::officeAlreadyHeld($role);
        }
    }

    private function memberHasOpenGroupLoan(GroupMember $member): bool
    {
        return Loan::query()
            ->where('group_id', $member->group_id)
            ->where('customer_id', $member->customer_id)
            ->with('schedules')
            ->get()
            ->contains(fn (Loan $loan): bool => $loan->isOpen());
    }
}
