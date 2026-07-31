<?php

declare(strict_types=1);

namespace App\Domain\Organization\Actions;

use App\Domain\Organization\DTOs\CompanyProfileData;
use App\Enums\AuditAction;
use App\Models\CompanyProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Updates the singleton company profile (PUT /company-profile).
 *
 * Changing `headquarters_branch_id` here does NOT move the
 * `branches.is_head_office` flag. The two are moved together only by
 * SetHeadOfficeAction, which is the one operation that guarantees exactly one
 * branch holds the flag. Promoting a branch as a side effect of editing a TIN
 * number would be a surprising amount of consequence for that form.
 */
final class UpdateCompanyProfileAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(CompanyProfileData $data, User $actor): CompanyProfile
    {
        return DB::transaction(function () use ($data, $actor): CompanyProfile {
            $profile = CompanyProfile::current();

            $before = $this->snapshot($profile);

            $profile->update([
                'legal_name' => $data->legalName,
                'trading_name' => $data->tradingName,
                'registration_number' => $data->registrationNumber,
                'tin_number' => $data->tinNumber,
                'phone' => $data->phone,
                'email' => $data->email,
                'address' => $data->address,
                'headquarters_branch_id' => $data->headquartersBranchId,
                'updated_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::CompanyProfileUpdated,
                $profile,
                before: $before,
                after: $this->snapshot($profile->refresh()),
                actor: $actor,
            );

            return $profile->load('headquarters');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CompanyProfile $profile): array
    {
        return [
            'legal_name' => $profile->legal_name,
            'trading_name' => $profile->trading_name,
            'registration_number' => $profile->registration_number,
            'tin_number' => $profile->tin_number,
            'phone' => $profile->phone,
            'email' => $profile->email,
            'address' => $profile->address,
            'headquarters_branch_id' => $profile->headquarters_branch_id,
        ];
    }
}
