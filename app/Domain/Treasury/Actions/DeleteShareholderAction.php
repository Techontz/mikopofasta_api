<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Actions;

use App\Domain\Treasury\Exceptions\ShareholderInUseException;
use App\Enums\AuditAction;
use App\Models\Shareholder;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Removes a shareholder (DELETE /shareholders/{shareholder}).
 *
 * Refused once capital has been recorded against them: the contribution is a
 * posted ledger event, and a contribution whose shareholder has vanished
 * cannot be explained to an auditor.
 */
final class DeleteShareholderAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Shareholder $shareholder, User $actor): void
    {
        $recorded = $shareholder->contributions()->count();

        if ($recorded > 0) {
            throw ShareholderInUseException::hasContributions($recorded);
        }

        DB::transaction(function () use ($shareholder, $actor): void {
            $this->audit->log(
                AuditAction::ShareholderDeleted,
                $shareholder,
                before: $shareholder->only(['full_name', 'phone', 'email']),
                actor: $actor,
            );

            $shareholder->delete();
        });
    }
}
