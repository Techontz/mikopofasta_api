<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CompanyProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `CompanyProfileSchema` in the frontend's types/organization.ts.
 *
 * Note the id: the frontend declares it as `z.literal("company-profile")`, not
 * a numeric string like every other resource. Emitting the row's primary key
 * would fail that literal check, so the constant is returned instead — this is
 * a singleton, so the real key carries no information anyway.
 *
 * @mixin CompanyProfile
 */
final class CompanyProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => CompanyProfile::PUBLIC_ID,
            'legalName' => $this->legal_name,
            'tradingName' => $this->trading_name,
            'registrationNumber' => $this->registration_number,
            'tinNumber' => $this->tin_number,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'headquartersBranchId' => $this->headquarters_branch_id === null
                ? null
                : (string) $this->headquarters_branch_id,
            'updatedBy' => $this->updated_by === null ? null : (string) $this->updated_by,
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
