<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\Exceptions\ExpenseException;
use App\Enums\ActiveStatus;
use App\Enums\AuditAction;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Retires a category.
 *
 * The frontend's confirmation says it exactly: "New requests will no longer be
 * able to file against this name. Requests already filed keep it." So this is
 * a soft delete, and it takes the ledger account out of service with it —
 * LedgerService refuses to post to an inactive account, which is what makes
 * "no new requests" true at the posting layer rather than only in the picker.
 *
 * The account is deactivated, never deleted: it holds every shilling ever spent
 * under this name, and last year's P&L still has to be able to read it.
 */
final class DeleteExpenseCategoryAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ExpenseCategory $category, User $actor): void
    {
        $this->guardNotInUse($category);

        DB::transaction(function () use ($category, $actor): void {
            $category->chartAccount()->update(['status' => ActiveStatus::Inactive]);

            $this->audit->log(
                AuditAction::ExpenseCategoryDeleted,
                $category,
                before: ['name' => $category->name, 'scope' => $category->scope->value],
                actor: $actor,
            );

            $category->delete();
        });
    }

    /**
     * A category with a pending request cannot be retired.
     *
     * Approved and rejected requests are history and keep their category
     * through the soft delete; a *pending* one is a decision someone still has
     * to make, and it must not be made against a name that no longer exists.
     */
    private function guardNotInUse(ExpenseCategory $category): void
    {
        $pending = $category->requests()
            ->whereNull('decided_at')
            ->exists();

        if ($pending) {
            throw ExpenseException::categoryInUse($category->name);
        }
    }
}
