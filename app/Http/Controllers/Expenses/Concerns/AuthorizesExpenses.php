<?php

declare(strict_types=1);

namespace App\Http\Controllers\Expenses\Concerns;

use App\Domain\Expenses\Policies\ExpensePolicy;
use App\Models\ExpenseRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ExpensePolicy covers the whole module and most of its abilities are not bound
 * to a model, so it is called directly rather than through $this->authorize() —
 * the same arrangement AuthorizesCapital uses, for the same reason.
 */
trait AuthorizesExpenses
{
    private function authorizeExpenses(string $ability, Request $request): void
    {
        abort_unless(app(ExpensePolicy::class)->{$ability}($this->actor($request)), Response::HTTP_FORBIDDEN);
    }

    /** §14: whoever raised a request may not be the one who approves it. */
    private function authorizeExpenseDecision(Request $request, ExpenseRequest $expenseRequest): void
    {
        abort_unless(
            app(ExpensePolicy::class)->decide($this->actor($request), $expenseRequest),
            Response::HTTP_FORBIDDEN,
        );
    }

    private function authorizeExpenseDeletion(Request $request, ExpenseRequest $expenseRequest): void
    {
        abort_unless(
            app(ExpensePolicy::class)->delete($this->actor($request), $expenseRequest),
            Response::HTTP_FORBIDDEN,
        );
    }
}
