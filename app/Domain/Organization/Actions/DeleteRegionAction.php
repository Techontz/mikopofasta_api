<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\Exceptions\OrganizationInUseException;
use App\Enums\AuditAction;
use App\Models\Region;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes a region (DELETE /regions/{region}).
 *
 * Regions carry no `deleted_at` in spec §2.2 — they are reference data, not
 * business records — so this really does remove the row. That makes the
 * guards below the only thing standing between a delete and an
 * integrity-constraint violation: districts, branches and users all reference
 * a region with RESTRICT.
 */
final class DeleteRegionAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Region $region, User $actor): void
    {
        if ($region->branches()->exists()) {
            throw OrganizationInUseException::regionHasBranches();
        }

        if ($region->districts()->exists()) {
            throw OrganizationInUseException::regionHasDistricts();
        }

        $users = $region->users()->count();

        if ($users > 0) {
            throw OrganizationInUseException::regionHasUsers($users);
        }

        DB::transaction(function () use ($region, $actor): void {
            // Logged before the delete: audit_logs.auditable_id would still
            // hold the id afterwards, but reading the name back would not.
            $this->audit->log(
                AuditAction::RegionDeleted,
                $region,
                before: ['name' => $region->name],
                actor: $actor,
            );

            $region->delete();
        });
    }
}
