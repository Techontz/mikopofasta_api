<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Enums\GroupRole;
use App\Domain\Customers\Services\GroupService;
use App\Enums\ActiveStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreGroupMemberRequest;
use App\Http\Requests\Customers\StoreGroupRequest;
use App\Http\Resources\GroupMemberResource;
use App\Http\Resources\GroupResource;
use App\Models\Customer;
use App\Models\Group;
use App\Models\GroupMember;
use App\Support\ApiResponse;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Group → All Groups.
 *
 * A translation layer only: every rule about who may join a group, hold an
 * office or leave lives in GroupService, so the same rule applies whether a
 * membership is changed here or by a seeder.
 */
final class GroupController extends Controller
{
    public function __construct(private readonly GroupService $groups) {}

    /** GET /api/v1/groups */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Group::class);

        $groups = Group::query()
            ->with(['branch', 'activeMembers.customer'])
            ->withCount('activeMembers')
            ->when($request->string('search')->toString() !== '', function ($q) use ($request): void {
                $term = '%'.$request->string('search')->toString().'%';
                $q->where('name', 'like', $term);
            })
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->integer('branch_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->orderBy('name')
            ->paginate(ApiResponse::perPage($request->input('per_page')));

        /*
         * The outstanding balance is per-group and derived from loan schedules,
         * so it is attached after paging — computing it for every group in the
         * table would load the whole loan book to render one page.
         */
        $groups->getCollection()->each(function (Group $group): void {
            $group->outstanding_balance = $this->groups->outstandingBalance($group)->toDecimalString();
        });

        return ApiResponse::paginated($groups, GroupResource::class);
    }

    /** POST /api/v1/groups */
    public function store(StoreGroupRequest $request): JsonResponse
    {
        $this->authorize('create', Group::class);

        $group = Group::create([
            'name' => $request->string('name')->toString(),
            'branch_id' => $request->integer('branchId'),
            'status' => ActiveStatus::Active,
            'meeting_day' => $request->input('meetingDay'),
            'meeting_time' => $request->input('meetingTime'),
        ]);

        // The column defaults are applied by the database, so the in-memory
        // model is missing them until it is re-read.
        $group->refresh();

        return ApiResponse::data(
            new GroupResource($group->load(['branch', 'activeMembers.customer'])->loadCount('activeMembers')),
            status: Response::HTTP_CREATED,
        );
    }

    /** GET /api/v1/groups/{group} */
    public function show(Group $group): JsonResponse
    {
        $this->authorize('view', $group);

        $group->load(['branch', 'activeMembers.customer'])->loadCount('activeMembers');
        $group->outstanding_balance = $this->groups->outstandingBalance($group)->toDecimalString();

        return ApiResponse::data(new GroupResource($group));
    }

    /** PUT /api/v1/groups/{group} */
    public function update(StoreGroupRequest $request, Group $group): JsonResponse
    {
        $this->authorize('update', $group);

        $group->update([
            'name' => $request->string('name')->toString(),
            'branch_id' => $request->integer('branchId'),
            'meeting_day' => $request->input('meetingDay'),
            'meeting_time' => $request->input('meetingTime'),
        ]);

        return ApiResponse::data(
            new GroupResource($group->load(['branch', 'activeMembers.customer'])->loadCount('activeMembers')),
        );
    }

    /**
     * DELETE /api/v1/groups/{group}
     *
     * Closes rather than erases: GroupService refuses while money is
     * outstanding, and the membership history is evidence for who guaranteed a
     * loan that may still be running.
     */
    public function destroy(Group $group): JsonResponse
    {
        $this->authorize('delete', $group);

        $this->groups->close($group);
        $group->delete();

        return ApiResponse::data(['message' => 'Group closed.']);
    }

    /** POST /api/v1/groups/{group}/members */
    public function addMember(StoreGroupMemberRequest $request, Group $group): JsonResponse
    {
        $this->authorize('manageMembers', $group);

        $member = $this->groups->addMember(
            $group,
            Customer::findOrFail($request->integer('customerId')),
            GroupRole::from($request->string('role')->toString() ?: GroupRole::Member->value),
            $request->filled('joinedAt') ? Carbon::parse($request->string('joinedAt')->toString()) : null,
        );

        return ApiResponse::data(
            new GroupMemberResource($member->load('customer')),
            status: Response::HTTP_CREATED,
        );
    }

    /** PATCH /api/v1/groups/{group}/members/{member} */
    public function updateMember(StoreGroupMemberRequest $request, Group $group, GroupMember $member): JsonResponse
    {
        $this->authorize('manageMembers', $group);

        $updated = $this->groups->assignRole(
            $member,
            GroupRole::from($request->string('role')->toString()),
        );

        return ApiResponse::data(new GroupMemberResource($updated->load('customer')));
    }

    /** DELETE /api/v1/groups/{group}/members/{member} */
    public function removeMember(Group $group, GroupMember $member): JsonResponse
    {
        $this->authorize('manageMembers', $group);

        $this->groups->removeMember($member);

        return ApiResponse::data(['message' => 'Member removed from the group.']);
    }
}
