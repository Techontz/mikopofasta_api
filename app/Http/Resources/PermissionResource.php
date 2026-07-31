<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Permission;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single permission, carrying the label and group the permission matrix
 * renders it under (mirrors PERMISSION_LABELS / PERMISSION_GROUPS in the
 * frontend's config/permissions.ts).
 *
 * @mixin Permission
 */
final class PermissionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'label' => $this->label(),
            'group' => $this->group(),
        ];
    }
}
