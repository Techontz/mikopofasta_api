<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Shareholder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `ShareholderSchema` in the frontend's types/capital.ts.
 *
 * @mixin Shareholder
 */
final class ShareholderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'fullName' => $this->full_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'gender' => $this->gender,
            'dateOfBirth' => $this->date_of_birth->toDateString(),
            'deletedAt' => $this->deleted_at?->toIso8601String(),

            // Drives whether the delete action is offered at all.
            'contributionCount' => $this->whenCounted('contributions'),
        ];
    }
}
