<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\DTOs\ShareholderData;
use App\Enums\AuditAction;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Registers a shareholder (POST /shareholders). */
final class CreateShareholderAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ShareholderData $data, User $actor): Shareholder
    {
        return DB::transaction(function () use ($data, $actor): Shareholder {
            // fill()->save() rather than create() with a unioned array: the
            // union erases the attribute names, so nothing can check them
            // against the model. This is also how UpdateShareholderAction
            // writes the same fields.
            $shareholder = new Shareholder;
            $shareholder->fill($data->toAttributes());
            $shareholder->created_by = $actor->getKey();
            $shareholder->save();

            $this->audit->log(
                AuditAction::ShareholderRegistered,
                $shareholder,
                after: $data->toAttributes(),
                actor: $actor,
            );

            return $shareholder;
        });
    }
}
