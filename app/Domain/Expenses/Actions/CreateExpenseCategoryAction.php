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
 * Registers a name that can be spent against, and mints the ledger account it
 * owns (ACCOUNT OVERVIEW §G).
 *
 * Both in one transaction: a category whose account failed to create would be
 * a budget line nothing can post to, and an account with no category would be
 * an orphan on the trial balance. Neither is allowed to exist.
 */
final class CreateExpenseCategoryAction
{
    public function __construct(
        private readonly ExpenseAccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ExpenseCategoryData $data, User $actor): ExpenseCategory
    {
        $this->guardUnique($data);

        return DB::transaction(function () use ($data, $actor): ExpenseCategory {
            // The account first: `chart_account_id` is NOT NULL, so there is no
            // moment at which a category exists without the ledger it owns.
            $account = $this->accounts->createAccountFor($data->name);

            $category = ExpenseCategory::query()->create([
                'name' => $data->name,
                'scope' => $data->scope,
                'chart_account_id' => $account->getKey(),
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::ExpenseCategoryCreated,
                $category,
                after: [
                    'name' => $category->name,
                    'scope' => $category->scope->value,
                    'chart_account_id' => $account->getKey(),
                    'chart_account_code' => $account->code,
                ],
                actor: $actor,
            );

            return $category->load('chartAccount');
        });
    }

    /**
     * Checked here as well as by the unique index, so the caller gets the
     * module's own message rather than a driver-level integrity error.
     */
    private function guardUnique(ExpenseCategoryData $data): void
    {
        $exists = ExpenseCategory::query()
            ->where('scope', $data->scope)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($data->name)])
            ->exists();

        if ($exists) {
            throw ExpenseException::duplicateCategory($data->name);
        }
    }
}
