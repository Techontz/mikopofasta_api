<?php

namespace App\Services\Messaging;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Zone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may chat with whom (handwritten notes "COMMUNICATION SYSTEM"):
 * everyone chats depending on position and branch; HQ roles (HR, Finance, Admin…) reach every employee;
 * normal employees only reach their specific head (branch staff → branch manager → zone manager → HQ);
 * heads reach their subordinates and can create groups / broadcasts inside the branch or zone they are allowed.
 */
class ChatDirectory
{
    public const HQ = 'hq';

    public const ZONE = 'zone';

    public const BRANCH_HEAD = 'branch_head';

    public const STAFF = 'staff';

    /**
     * Role ids per hierarchy level, cached per company for the lifetime of this instance.
     *
     * @var array<int, array<string, list<int>>>
     */
    private array $roleLevels = [];

    /** Roles treated as the top of the hierarchy when a branch or zone has no head. */
    private const ADMIN_ROLES = ['super_admin', 'admin'];

    /**
     * Position of an employee in the messaging hierarchy.
     */
    public function level(Employee $employee): string
    {
        $role = $employee->role;

        return match (true) {
            $role?->scope === 'company' => self::HQ,
            $role?->scope === 'zone' => self::ZONE,
            $role?->key === 'branch_manager' => self::BRANCH_HEAD,
            default => self::STAFF,
        };
    }

    public function isHead(Employee $employee): bool
    {
        return $this->level($employee) !== self::STAFF;
    }

    /**
     * Employees the given employee may start a conversation with.
     *
     * @return Builder<Employee>
     */
    public function contacts(Employee $employee): Builder
    {
        $query = Employee::query()->staff()
            ->where('company_id', $employee->company_id)
            ->where('status', 'active')
            ->whereKeyNot($employee->id);

        return match ($this->level($employee)) {
            self::HQ => $query,
            self::ZONE => $query->where(fn (Builder $inner) => $inner
                ->where(fn (Builder $branchStaff) => $branchStaff
                    ->whereIn('branch_id', $this->zoneBranchIds($employee->zone_id))
                    ->where(fn (Builder $levels) => $this->whereLevel($levels, $employee, [self::BRANCH_HEAD, self::STAFF])))
                ->orWhere(fn (Builder $hq) => $this->whereLevel($hq, $employee, [self::HQ]))),
            self::BRANCH_HEAD => $query->where(fn (Builder $inner) => $inner
                ->where(fn (Builder $branchStaff) => $branchStaff
                    ->where('branch_id', $employee->branch_id)
                    ->where(fn (Builder $levels) => $this->whereLevel($levels, $employee, [self::BRANCH_HEAD, self::STAFF])))
                ->orWhereIn('id', $this->headIdsAbove($employee, self::BRANCH_HEAD))),
            default => $query->whereIn('id', $this->headIdsAbove($employee, self::STAFF)),
        };
    }

    public function canMessage(Employee $from, Employee $to): bool
    {
        return $this->contacts($from)->whereKey($to->id)->exists();
    }

    /**
     * The "specific head" of an employee: branch manager(s) for staff, zone manager(s) for branch managers,
     * falling back up the hierarchy to company admins when a level is vacant.
     *
     * @return list<int>
     */
    public function headIdsAbove(Employee $employee, string $level): array
    {
        $active = fn (): Builder => Employee::staff()->where('company_id', $employee->company_id)->where('status', 'active')->whereKeyNot($employee->id);
        $zoneId = $employee->zone_id ?? Branch::whereKey($employee->branch_id)->value('zone_id');

        if ($level === self::STAFF && $employee->branch_id) {
            $managers = $active()->where('branch_id', $employee->branch_id)
                ->whereIn('role_id', $this->roleIds($employee, [self::BRANCH_HEAD]))->pluck('id')->all();
            if ($managers !== []) {
                return $managers;
            }
        }

        if (in_array($level, [self::STAFF, self::BRANCH_HEAD], true) && $zoneId) {
            $zoneManagers = $active()->where('zone_id', $zoneId)
                ->whereIn('role_id', $this->roleIds($employee, [self::ZONE]))->pluck('id')->all();
            if ($zoneManagers !== []) {
                return $zoneManagers;
            }
        }

        return $active()->whereHas('role', fn (Builder $role) => $role->whereIn('key', self::ADMIN_ROLES))->pluck('id')->all();
    }

    /**
     * Broadcast audiences an employee may post to: [{value: "branch:3", label, type, id}].
     *
     * @return Collection<int, array{value: string, label: string, type: string, id: int|null}>
     */
    public function audiences(Employee $employee): Collection
    {
        $audiences = collect();
        $level = $this->level($employee);

        if ($level === self::HQ) {
            $audiences->push(['value' => 'company:0', 'label' => 'ALL STAFF', 'type' => 'company', 'id' => null]);
            Zone::where('company_id', $employee->company_id)->orderBy('name')->get()
                ->each(fn (Zone $zone) => $audiences->push(['value' => "zone:{$zone->id}", 'label' => 'ZONE: '.$zone->name, 'type' => 'zone', 'id' => $zone->id]));
            Branch::where('company_id', $employee->company_id)->orderBy('name')->get()
                ->each(fn (Branch $branch) => $audiences->push(['value' => "branch:{$branch->id}", 'label' => 'BRANCH: '.$branch->name, 'type' => 'branch', 'id' => $branch->id]));
        } elseif ($level === self::ZONE && $employee->zone_id) {
            $zone = Zone::find($employee->zone_id);
            $audiences->push(['value' => "zone:{$zone->id}", 'label' => 'ZONE: '.$zone->name, 'type' => 'zone', 'id' => $zone->id]);
            Branch::where('zone_id', $zone->id)->orderBy('name')->get()
                ->each(fn (Branch $branch) => $audiences->push(['value' => "branch:{$branch->id}", 'label' => 'BRANCH: '.$branch->name, 'type' => 'branch', 'id' => $branch->id]));
        } elseif ($level === self::BRANCH_HEAD && $employee->branch_id) {
            $branch = Branch::find($employee->branch_id);
            $audiences->push(['value' => "branch:{$branch->id}", 'label' => 'BRANCH: '.$branch->name, 'type' => 'branch', 'id' => $branch->id]);
        }

        return $audiences;
    }

    /**
     * Active employee ids that belong to a broadcast audience.
     *
     * @return list<int>
     */
    public function audienceMemberIds(Employee $sender, string $type, ?int $id): array
    {
        $query = Employee::staff()->where('company_id', $sender->company_id)->where('status', 'active');

        match ($type) {
            'branch' => $query->where('branch_id', $id)
                ->where(fn (Builder $levels) => $this->whereLevel($levels, $sender, [self::BRANCH_HEAD, self::STAFF])),
            'zone' => $query->where(fn (Builder $inner) => $inner
                ->where(fn (Builder $branchStaff) => $branchStaff
                    ->whereIn('branch_id', $this->zoneBranchIds($id))
                    ->where(fn (Builder $levels) => $this->whereLevel($levels, $sender, [self::BRANCH_HEAD, self::STAFF])))
                ->orWhere(fn (Builder $zoneManagers) => $zoneManagers->where('zone_id', $id)
                    ->whereIn('role_id', $this->roleIds($sender, [self::ZONE])))),
            default => $query,
        };

        return array_values(array_unique([...$query->pluck('id')->all(), $sender->id]));
    }

    /**
     * Constrain employees to hierarchy levels (employees without a role count as staff).
     *
     * @param  Builder<Employee>  $query
     * @param  list<string>  $levels
     */
    private function whereLevel(Builder $query, Employee $employee, array $levels): void
    {
        $query->whereIn('role_id', $this->roleIds($employee, $levels));
        if (in_array(self::STAFF, $levels, true)) {
            $query->orWhereNull('role_id');
        }
    }

    /**
     * @return list<int>
     */
    private function zoneBranchIds(?int $zoneId): array
    {
        return $zoneId ? Branch::where('zone_id', $zoneId)->pluck('id')->all() : [];
    }

    /**
     * Role ids of the company whose hierarchy level is one of the given levels.
     *
     * @param  list<string>  $levels
     * @return list<int>
     */
    private function roleIds(Employee $employee, array $levels): array
    {
        $companyId = $employee->company_id;

        $this->roleLevels[$companyId] ??= Role::where('company_id', $companyId)->get()
            ->groupBy(fn (Role $role): string => match (true) {
                $role->scope === 'company' => self::HQ,
                $role->scope === 'zone' => self::ZONE,
                $role->key === 'branch_manager' => self::BRANCH_HEAD,
                default => self::STAFF,
            })
            ->map(fn (Collection $roles): array => $roles->pluck('id')->all())
            ->all();

        return collect($levels)->flatMap(fn (string $level): array => $this->roleLevels[$companyId][$level] ?? [])->values()->all();
    }
}
