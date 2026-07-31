<?php

declare(strict_types=1);

namespace App\Domain\Hr\Enums;

/**
 * Mirrors the frontend's PAYROLL_RUN_STATUSES and `payroll_runs.status` (§2.9).
 *
 * The three states are three different people's work, which is the whole point
 * of §14's separation of duties: HR generates the draft, Finance finalizes it
 * (the step that posts), and Finance executes the payment.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Finalized = 'finalized';
    case Paid = 'paid';

    /** Nothing has reached the ledger while a run is still a draft. */
    public function isPosted(): bool
    {
        return $this !== self::Draft;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
