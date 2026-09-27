<?php

namespace App\Http\Controllers\Api\V1\Shares;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\ShareHolder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Base of the Shares API controllers: company scoping of bound models and presentation of register rows.
 */
abstract class SharesController extends ApiController
{
    /**
     * Records of another company are reported as not found.
     */
    protected function ensureCompany(Model $model): void
    {
        abort_unless((int) $model->getAttribute('company_id') === (int) $this->currentEmployee()->company_id, 404);
    }

    protected function companyId(): int
    {
        return (int) $this->currentEmployee()->company_id;
    }

    protected function date(Request $request, string $field): ?CarbonImmutable
    {
        return $request->filled($field) ? CarbonImmutable::parse($request->string($field)->toString()) : null;
    }

    protected function canSeeCapital(): bool
    {
        return Gate::allows('capital.view') || Gate::allows('capital.manage');
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentHolder(ShareHolder $holder): array
    {
        return [
            'id' => $holder->id,
            'name' => $holder->full_name,
            'first_name' => $holder->first_name,
            'middle_name' => $holder->middle_name,
            'last_name' => $holder->last_name,
            'mobile' => $holder->mobile,
            'email' => $holder->email,
            'gender' => $holder->gender,
            'date_of_birth' => $holder->date_of_birth?->toDateString(),
            'photo_endpoint' => $holder->passport_photo ? "shares/share-holders/{$holder->id}/photo?v=".$holder->updated_at?->timestamp : null,
        ];
    }

    /**
     * @param  array{share_holder: ShareHolder, shares: int, total_shares: int, ownership_percent: float, share_value: float, holding_value: float, date_acquired?: ?string, status?: string}  $row
     * @return array<string, mixed>
     */
    protected function presentRow(array $row): array
    {
        return [
            'share_holder_id' => $row['share_holder']->id,
            'name' => $row['share_holder']->full_name,
            'shares' => $row['shares'],
            'total_shares' => $row['total_shares'],
            'ownership_percent' => $row['ownership_percent'],
            'share_value' => $row['share_value'],
            'holding_value' => $row['holding_value'],
            'date_acquired' => $row['date_acquired'] ?? null,
            'status' => $row['status'] ?? ($row['shares'] > 0 ? 'active' : 'no_shares'),
        ] + array_intersect_key($row, array_flip(['rank', 'cumulative_percent']));
    }
}
