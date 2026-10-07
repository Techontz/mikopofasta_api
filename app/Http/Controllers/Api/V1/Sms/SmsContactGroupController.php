<?php

namespace App\Http\Controllers\Api\V1\Sms;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\SmsContactGroup;
use App\Services\Sms\SmsSender;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SMS Centre → Contact Groups: named lists of phone numbers (staff, agents, partners, anyone who is not a customer) that
 * an announcement can be sent to. Saving a group replaces its whole member list.
 */
class SmsContactGroupController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizeAny('sms.manage');

        $groups = SmsContactGroup::where('company_id', $this->currentEmployee()->company_id)->withCount('members')->orderBy('name')->get()
            ->map(fn (SmsContactGroup $group): array => ['id' => $group->id, 'name' => $group->name, 'description' => $group->description, 'members_count' => $group->members_count]);

        return response()->json(['data' => $groups]);
    }

    public function show(SmsContactGroup $contactGroup): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $this->assertOwned($contactGroup);

        return response()->json(['data' => $this->row($contactGroup)]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $data = $this->validated($request);

        $group = DB::transaction(function () use ($data): SmsContactGroup {
            $group = SmsContactGroup::create(['company_id' => $this->currentEmployee()->company_id, 'name' => $data['name'], 'description' => $data['description'] ?? null]);
            $this->syncMembers($group, $data['members']);

            return $group;
        });

        return $this->message('Contact group saved successfully', 201, ['data' => $this->row($group)]);
    }

    public function update(Request $request, SmsContactGroup $contactGroup): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $this->assertOwned($contactGroup);
        $data = $this->validated($request);

        DB::transaction(function () use ($contactGroup, $data): void {
            $contactGroup->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);
            $this->syncMembers($contactGroup, $data['members']);
        });

        return $this->message('Contact group updated successfully', 200, ['data' => $this->row($contactGroup)]);
    }

    public function destroy(SmsContactGroup $contactGroup): JsonResponse
    {
        $this->authorizeAny('sms.manage');
        $this->assertOwned($contactGroup);
        $contactGroup->delete();

        return $this->message('Contact group deleted successfully');
    }

    /**
     * @return array{name: string, description?: string|null, members: list<array{name?: string|null, phone: string}>}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'members' => ['required', 'array', 'min:1', 'max:5000'],
            'members.*.name' => ['nullable', 'string', 'max:100'],
            'members.*.phone' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (SmsSender::normalisePhone((string) $value) === null) {
                    $fail("{$value} is not a valid phone number (use 07XXXXXXXX or 2557XXXXXXXX).");
                }
            }],
        ], ['members.required' => 'Add at least one phone number.']);
    }

    /**
     * @param  list<array{name?: string|null, phone: string}>  $members
     */
    private function syncMembers(SmsContactGroup $group, array $members): void
    {
        $group->members()->delete();
        $rows = collect($members)
            ->map(fn (array $member): array => ['name' => filled($member['name'] ?? null) ? trim((string) $member['name']) : null, 'phone' => (string) SmsSender::normalisePhone($member['phone'])])
            ->unique('phone')
            ->map(fn (array $member): array => $member + ['sms_contact_group_id' => $group->id, 'created_at' => now(), 'updated_at' => now()]);

        foreach ($rows->chunk(500) as $chunk) {
            $group->members()->insert($chunk->values()->all());
        }
    }

    private function assertOwned(SmsContactGroup $group): void
    {
        abort_unless((int) $group->company_id === (int) $this->currentEmployee()->company_id, 404);
    }

    /**
     * @return array{id: int, name: string, description: string|null, members: list<array{name: string|null, phone: string}>}
     */
    private function row(SmsContactGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'description' => $group->description,
            'members' => $group->members()->get(['name', 'phone'])->map(fn ($member): array => ['name' => $member->name, 'phone' => $member->phone])->all(),
        ];
    }
}
