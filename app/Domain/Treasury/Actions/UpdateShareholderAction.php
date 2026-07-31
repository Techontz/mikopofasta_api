<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\DTOs\ShareholderData;
use App\Enums\AuditAction;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Edits a shareholder (PUT /shareholders/{shareholder}). */
final class UpdateShareholderAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Shareholder $shareholder, ShareholderData $data, User $actor): Shareholder
    {
        return DB::transaction(function () use ($shareholder, $data, $actor): Shareholder {
            $before = $shareholder->only(['full_name', 'phone', 'email', 'gender', 'date_of_birth']);

            $shareholder->fill($data->toAttributes())->save();

            $this->audit->log(
                AuditAction::ShareholderUpdated,
                $shareholder,
                before: $before,
                after: $data->toAttributes(),
                actor: $actor,
            );

            return $shareholder->refresh();
        });
    }
}
