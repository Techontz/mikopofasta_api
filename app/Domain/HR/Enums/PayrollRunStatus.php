<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/**
 * Mirrors the frontend's PAYROLL_RUN_STATUSES and `payroll_runs.status` (§2.9).
 *
 * Four states, and each is a different person's work — which is the whole point
 * of §14's separation of duties and of §16.7–16.8, where the HR document says
 * plainly that HR approves and Finance disburses:
 *
 *   draft      HR has generated the figures. Nothing is agreed and nothing has
 *              posted; the run may be regenerated as many times as needed.
 *   approved   HR has signed the figures off. §16.1 — "Salary haiwezi
 *              kubadilishwa baada ya approval" — makes this the moment they
 *              stop being editable, so regeneration is refused from here on.
 *   finalized  Finance has posted the recognition and deduction entries. The
 *              company now owes each employee their net pay.
 *   paid       Finance has settled it. Staff Payable nets to zero.
 *
 * `approved` was added in Module 7. Before it, a draft could be regenerated
 * right up to the moment Finance posted it, so there was no point at which the
 * figures became the agreed figures — and §16.1 has nothing to refer to unless
 * such a point exists.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Finalized = 'finalized';
    case Paid = 'paid';

    /** Nothing has reached the ledger until Finance finalizes. */
    public function isPosted(): bool
    {
        return $this === self::Finalized || $this === self::Paid;
    }

    /**
     * Whether the figures may still be recomputed.
     *
     * §16.1's rule, in one place. A draft may be regenerated freely; everything
     * from approval onwards is settled.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Approved',
            self::Finalized => 'Finalized',
            self::Paid => 'Paid',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
