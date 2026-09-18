<?php

declare(strict_types=1);

namespace App\Domain\Reversals\Executors;

use App\Domain\Reversals\Enums\ReversalType;
use App\Models\JournalEntry;
use App\Models\ReversalRequest;
use App\Models\User;
use App\Support\Money;

/**
 * One reversible kind of transaction.
 *
 * Four types, four executors, one contract. The split exists because undoing a
 * payment and undoing a disbursement have almost nothing in common beyond the
 * mirrored journal entry — one un-allocates a schedule, the other walks a loan
 * backwards through its lifecycle — and a single action with a `match` on the
 * type would have been that same code with the seams hidden.
 *
 * Every method here runs inside the caller's transaction. Nothing an executor
 * does may survive a failed approval.
 */
interface ReversalExecutor
{
    public function type(): ReversalType;

    /**
     * Refuses a request that cannot be reversed, at the moment it is RAISED.
     *
     * Called again at approval time, because the answer can change while the
     * request sits in the queue — a loan with no repayments when Finance asked
     * may have three by the time Admin decides.
     *
     * @throws \App\Domain\Reversals\Exceptions\ReversalRefusedException
     */
    public function guard(ReversalRequest $request): void;

    /** What the request proposes to reverse, for the approver to see. */
    public function amount(ReversalRequest $request): Money;

    /** A human reference for the subject — "PMT-000123", "Loan LN-0007 inst. 3". */
    public function describe(ReversalRequest $request): string;

    /**
     * Undoes it. Returns the mirrored entry, or null when the type posts none
     * (penalty accrual never reached the ledger).
     */
    public function execute(ReversalRequest $request, User $approver): ?JournalEntry;
}
