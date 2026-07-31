<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Customers\Enums\GroupRole;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `GroupSchema` in the frontend's types/group.ts.
 *
 * Money is a decimal string, as everywhere else on this API — the frontend
 * coerces once at its boundary rather than trusting a float across the wire.
 *
 * @mixin Group
 */
final class GroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'branchId' => (string) $this->branch_id,
            'branchName' => $this->whenLoaded('branch', fn (): string => $this->branch->name),
            'status' => $this->status->value,

            'meetingDay' => $this->meeting_day,
            'meetingTime' => $this->meeting_time,

            // The committee, pulled from the membership rows rather than stored
            // on the group, so it cannot disagree with who actually holds office.
            'leader' => $this->officer(GroupRole::Leader),
            'secretary' => $this->officer(GroupRole::Secretary),
            'treasurer' => $this->officer(GroupRole::Treasurer),

            'memberCount' => $this->whenCounted('activeMembers'),
            'outstandingBalance' => $this->when(
                isset($this->outstanding_balance),
                fn (): string => (string) $this->outstanding_balance,
            ),

            'members' => GroupMemberResource::collection($this->whenLoaded('activeMembers')),

            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }

    /** The name of whoever holds an office, or null if it is vacant. */
    private function officer(GroupRole $role): ?string
    {
        if (! $this->relationLoaded('activeMembers')) {
            return null;
        }

        $member = $this->activeMembers->first(
            fn (GroupMember $m): bool => $m->role === $role,
        );

        return $member?->customer?->fullName();
    }
}
