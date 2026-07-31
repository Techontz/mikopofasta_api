<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Domain\Expenses\DTOs\ExpenseRequestData;
use App\Domain\Expenses\Enums\ExpenseRequestStatus;
use App\Domain\Expenses\Enums\ExpenseScope;
use App\Domain\Expenses\Exceptions\ExpenseException;
use App\Domain\Expenses\Services\ExpenseAccountResolver;
use App\Enums\AuditAction;
use App\Models\ExpenseCategory;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Files an expense request — Expenses → All Expenses Request, and the
 * headquarters equivalent.
 *
 * Posts nothing. The request is a claim on money, not a movement of it; the
 * ledger is touched when someone approves (DecideExpenseRequestAction), which
 * is what keeps a queue of unapproved requests out of the trial balance.
 */
final class RequestExpenseAction
{
    public function __construct(
        private readonly ExpenseAccountResolver $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(ExpenseCategory $category, ExpenseRequestData $data, User $actor): ExpenseRequest
    {
        // Refused rather than silently re-scoped: the Expense Tagging Report
        // exists to find costs booked to the wrong register, and it should
        // never have to find one this system created itself.
        if ($data->expectedScope !== null && $data->expectedScope !== $category->scope) {
            throw ExpenseException::scopeMismatch();
        }

        $branchId = $this->resolveBranch($category, $data, $actor);

        return DB::transaction(function () use ($category, $data, $actor, $branchId): ExpenseRequest {
            $request = ExpenseRequest::query()->create([
                'reference' => $this->nextReference(),
                'expense_category_id' => $category->getKey(),
                // Copied from the category, never taken from the caller: the
                // register a cost lands in is a property of what was bought.
                'scope' => $category->scope,
                'branch_id' => $branchId,
                'requested_by' => $actor->getKey(),
                'amount' => $data->amount,
                'description' => $data->description,
                'comment' => $data->comment,
                'status' => ExpenseRequestStatus::Pending,
                'requested_on' => $data->requestedOn ?? Date::now()->toDateString(),
            ]);

            $this->audit->log(
                AuditAction::ExpenseRequested,
                $request,
                after: [
                    'reference' => $request->reference,
                    'expense_category_id' => $category->getKey(),
                    'category' => $category->name,
                    'scope' => $request->scope->value,
                    'branch_id' => $branchId,
                    'amount' => $request->amount,
                ],
                actor: $actor,
            );

            return $request->load(ExpenseRequest::LIST_RELATIONS);
        });
    }

    /**
     * Which branch bears the cost.
     *
     * A headquarters request always lands on head office, whatever the caller
     * sent — the register decides, not the form. A branch request takes the
     * branch it names, defaulting to the requester's own, which is what the
     * screen does: a branch officer filing for their own branch should not have
     * to say so.
     */
    private function resolveBranch(ExpenseCategory $category, ExpenseRequestData $data, User $actor): int
    {
        if ($category->scope === ExpenseScope::Headquarters) {
            return (int) $this->accounts->headOffice()->getKey();
        }

        $branchId = $data->branchId ?? $actor->branch_id;

        if ($branchId === null) {
            throw ExpenseException::branchRequired();
        }

        return (int) $branchId;
    }

    /**
     * EXP-0000001. Derived from the highest number in use rather than a row
     * count, so a soft-deleted request never lends its reference to a new one.
     */
    private function nextReference(): string
    {
        $highest = (int) DB::table('expense_requests')
            ->selectRaw('COALESCE(MAX(CAST(SUBSTRING(reference, 5) AS UNSIGNED)), 0) AS seq')
            ->value('seq');

        return 'EXP-'.str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT);
    }
}
