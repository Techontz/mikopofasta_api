<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\DTOs\ExpenseCategoryData;
use App\Domain\Expenses\Exceptions\ExpenseException;
use App\Domain\Expenses\Services\ExpenseAccountResolver;
use App\Enums\AuditAction;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Renames a category, and its ledger account with it.
 *
 * The scope is not editable. Moving a name between the branch and headquarters
 * registers would silently re-file every request already under it, changing
 * historical Branch P&L figures — the register is chosen once, at creation.
 */
final class UpdateExpenseCategoryAction
{
    public function __construct(
        private readonly ExpenseAccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ExpenseCategory $category, ExpenseCategoryData $data, User $actor): ExpenseCategory
    {
        $this->guardUnique($category, $data);

        return DB::transaction(function () use ($category, $data, $actor): ExpenseCategory {
            $before = ['name' => $category->name];

            $category->update(['name' => $data->name]);
            $this->accounts->renameAccountFor($category, $data->name);

            $this->audit->log(
                AuditAction::ExpenseCategoryUpdated,
                $category,
                before: $before,
                after: ['name' => $category->name],
                actor: $actor,
            );

            return $category->load('chartAccount');
        });
    }

    private function guardUnique(ExpenseCategory $category, ExpenseCategoryData $data): void
    {
        $exists = ExpenseCategory::query()
            ->where('scope', $category->scope)
            ->whereKeyNot($category->getKey())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data->name)])
            ->exists();

        if ($exists) {
            throw ExpenseException::duplicateCategory($data->name);
        }
    }
}
