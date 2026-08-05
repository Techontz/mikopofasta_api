<?php

declare(strict_types=1);

namespace App\Domain\Loans\Engine;

use App\Domain\Loans\DTOs\ScheduleInstallment;

/**
 * How one interest formula turns loan terms into a repayment plan.
 *
 * The extension point. Adding Rule of 78, a balloon loan, Murabaha or any
 * bespoke formula means writing one class against this interface and tagging
 * it — nothing in the engine, the actions, the controllers or the reports
 * changes, because none of them knows which strategy ran.
 *
 * ## The contract, in full
 *
 * An implementation MUST:
 *
 *   1. Return exactly `$terms->installmentCount()` installments, numbered from
 *      1, in ascending order.
 *   2. Use `$terms->dueDate($n)` for the due date. Cadence and grace belong to
 *      the terms, not to the formula — two products with the same schedule must
 *      fall due on the same days whatever their interest maths.
 *   3. Make the principal portions sum EXACTLY to `$terms->principal`. Not
 *      approximately: a loan whose schedule sums to a cent less than its own
 *      principal can never be closed. `Money::allocate()` distributes a
 *      remainder for you; a strategy that computes portions itself must give
 *      the residue to an installment deliberately.
 *   4. Be pure and deterministic. No clock, no database, no randomness, no
 *      floats. The same terms must always produce the same plan, because that
 *      is what makes a schedule verifiable after the fact.
 *
 * An implementation MUST NOT:
 *
 *   - Charge fees. Processing and insurance fees are a separate concern with
 *     their own ledger treatment; see LoanFeeCalculator.
 *   - Compute penalties. Penalties are independent of interest by business
 *     rule, and a formula that touched them would make a late payment change
 *     the interest that was agreed. See PenaltyCalculator.
 *   - Read anything the terms do not carry.
 */
interface InterestStrategy
{
    /**
     * The `interest_formulas.code` this implements.
     *
     * Matched case-insensitively against the administrator-managed row, so a
     * formula is chosen by configuration rather than by a branch in the engine.
     */
    public function code(): string;

    /**
     * A one-line description of the arithmetic, for administrators.
     *
     * Surfaced next to the formula when a product is configured, so whoever
     * picks it can see what it will do without reading this file.
     */
    public function describe(): string;

    /**
     * @return list<ScheduleInstallment>
     */
    public function schedule(LoanTerms $terms): array;
}
