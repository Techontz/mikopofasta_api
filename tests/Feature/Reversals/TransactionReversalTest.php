<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\Enums\ReversalStatus;
use App\Domain\Loans\Enums\DisbursementStatus;
use App\Domain\Loans\Enums\LoanStatus;
use App\Domain\Repayments\Enums\PaymentStatus;
use App\Domain\Reversals\Enums\ReversalType;
use App\Models\DisbursementBatch;
use App\Models\LoanAdvance;
use App\Models\Payment;
use App\Models\ReversalRequest;
use App\Support\Money;

/**
 * The reversal desk — §5, §14.
 *
 * Two things are being tested and they are not the same thing. One is the
 * CONTROL: two grants, two people, no self-approval. The other is the UNDO:
 * that a reversed payment leaves the loan where it stood before the money
 * arrived, which is the part that is worth having at all.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

/**
 * Raises a request as Finance, the role the client put at this desk.
 *
 * Returns the requester too: `officerAt()` mints a NEW user on every call, so a
 * test that wants "the same officer again" has to re-authenticate as the one it
 * got back rather than asking for another Finance officer.
 *
 * @return array{0: Illuminate\Testing\TestResponse, 1: ReversalRequest|null, 2: App\Models\User}
 */
function raiseReversal(ReversalType $type, int $subjectId, string $reason = 'Recorded in error'): array
{
    $requester = officerAt('Head Office', RoleName::Finance);

    $response = test()->postJson('/api/v1/reversals', [
        'reversal_type' => $type->value,
        'subject_id' => $subjectId,
        'reason' => $reason,
    ]);

    return [$response, ReversalRequest::query()->latest('id')->first(), $requester];
}

/** Puts an already-created user back in the guard. */
function actingAsAgain(App\Models\User $user): App\Models\User
{
    Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

    return $user;
}

describe('the approval control', function (): void {
    it('lets Finance raise a request without reversing anything', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        [$response, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        $response->assertCreated();

        expect($request->status)->toBe(ReversalStatus::Pending)
            ->and($request->reversal_type)->toBe(ReversalType::Payment)
            // The figure the approver will see, captured at request time.
            ->and($request->amount)->toBe($payment->amount)
            // Nothing has moved yet — that is the whole point of two steps.
            ->and($payment->fresh()->status)->not->toBe(PaymentStatus::Reversed);
    });

    it('refuses the requester approving their own reversal', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        [, $request, $requester] = raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        // The very same Finance officer, back again.
        actingAsAgain($requester);

        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertStatus(409);

        expect($request->fresh()->status)->toBe(ReversalStatus::Pending);
    });

    it('lets a SECOND Finance officer approve it', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        [, $request] = raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        officerAt('Head Office', RoleName::Finance, ['phone' => '255700000991']);

        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        expect($request->fresh()->status)->toBe(ReversalStatus::Approved);
    });

    it('lets Admin approve a Finance request', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        [, $request] = raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        actingAsRole(RoleName::Admin);

        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        expect($request->fresh()->status)->toBe(ReversalStatus::Approved);
    });

    it('refuses a role holding neither grant', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        // The Teller: cash entry and nothing else — §14's sharpest case.
        officerAt($loan->branch->name, RoleName::Teller);

        test()->postJson('/api/v1/reversals', [
            'reversal_type' => 'payment',
            'subject_id' => $payment->getKey(),
            'reason' => 'Let me undo my own cash',
        ])->assertForbidden();
    });

    it('refuses a second pending request for the same payment', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        raiseReversal(ReversalType::Payment, (int) $payment->getKey())[0]->assertCreated();
        raiseReversal(ReversalType::Payment, (int) $payment->getKey())[0]->assertStatus(409);
    });
});

describe('reversing a payment', function (): void {
    it('puts the loan back exactly where it stood', function (): void {
        $loan = matureLoan();

        $before = $loan->schedules()->where('installment_number', 1)->firstOrFail();
        $owedBefore = $before->outstandingTotal();

        payCash($loan, $owedBefore->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        expect($before->fresh()->outstandingTotal()->isZero())->toBeTrue();

        [, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $after = $before->fresh();

        expect($payment->fresh()->status)->toBe(PaymentStatus::Reversed)
            // The installment owes again, to the cent.
            ->and($after->outstandingTotal()->toDecimalString())->toBe($owedBefore->toDecimalString())
            ->and($after->principal_paid)->toBe('0.00')
            ->and($after->interest_paid)->toBe('0.00');
    });

    it('mirrors the journal entry rather than editing it', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $original = $payment->journalEntry;

        [, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $reversal = $request->fresh()->reversalEntry;

        expect($reversal)->not->toBeNull()
            ->and($reversal->is_reversal)->toBeTrue()
            ->and($reversal->reversed_entry_id)->toBe($original->getKey())
            // §5: the original's lines are untouched — that is what makes the
            // ledger auditable end to end.
            ->and($original->fresh()->load('lines')->lines->count())
            ->toBe($original->lines->count());
    });

    it('leaves negative allocations rather than deleting the evidence', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();
        $allocatedBefore = $payment->allocations()->count();

        [, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $rows = $payment->fresh()->allocations;

        expect($rows->count())->toBe($allocatedBefore * 2);

        // They net to zero: the loan owes what it owed, and the history says why.
        $net = Money::sum($rows->map(fn ($r) => $r->total()));
        expect($net->isZero())->toBeTrue();
    });

    it('reopens a loan the payment had closed', function (): void {
        $loan = fullyDueLoan();

        payCash($loan, $loan->outstandingTotal()->toDecimalString())->assertCreated();

        expect($loan->fresh()->status)->toBe(LoanStatus::Closed);

        $payment = Payment::query()->latest('id')->firstOrFail();

        [, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $reopened = $loan->fresh(['schedules']);

        expect($reopened->status)->toBeIn([LoanStatus::Active, LoanStatus::Arrears])
            // A reopened loan still carrying closed_at would be counted as
            // closed by every report that asks the cheap question.
            ->and($reopened->closed_at)->toBeNull()
            ->and($reopened->frozen_until)->toBeNull()
            ->and($reopened->outstandingTotal()->isPositive())->toBeTrue();
    });

    it('unwinds the advance credit a surplus created', function (): void {
        $loan = matureLoan();

        $due = installmentTotal($loan)->toDecimalString();
        $surplus = Money::of($due)->add(Money::of('50000.00'));

        payCash($loan, $surplus->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        expect(LoanAdvance::balanceFor((int) $loan->getKey())->isPositive())->toBeTrue();

        [, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        expect(LoanAdvance::balanceFor((int) $loan->getKey())->isZero())->toBeTrue();
    });

    it('refuses to reverse the same payment twice', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        [, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        raiseReversal(ReversalType::Payment, (int) $payment->getKey())[0]->assertStatus(409);
    });
});

describe('reversing a disbursement', function (): void {
    it('parks the loan where it can be picked up again', function (): void {
        $loan = activeLoan();
        $batch = DisbursementBatch::query()->where('loan_id', $loan->getKey())->firstOrFail();

        [, $request] = raiseReversal(ReversalType::Disbursement, (int) $batch->getKey(), 'Paid to the wrong number');

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $reverted = $loan->fresh();

        expect($reverted->status)->toBe(LoanStatus::DisbursementFailed)
            // A loan awaiting disbursement that still carried a disbursement
            // date would be read as live by every arrears report.
            ->and($reverted->disbursement_date)->toBeNull()
            ->and($reverted->expected_completion_date)->toBeNull()
            ->and($batch->fresh()->settled_loan_id)->toBeNull()
            ->and($batch->fresh()->status)->toBe(DisbursementStatus::Failed);
    });

    it('refuses while the loan has repayments against it', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $batch = DisbursementBatch::query()->where('loan_id', $loan->getKey())->firstOrFail();

        // Refused at REQUEST time — an approver should never see this in the
        // queue at all.
        raiseReversal(ReversalType::Disbursement, (int) $batch->getKey())[0]->assertStatus(409);
    });

    it('leaves the loan able to be disbursed again', function (): void {
        $loan = activeLoan();
        $batch = DisbursementBatch::query()->where('loan_id', $loan->getKey())->firstOrFail();

        [, $request] = raiseReversal(ReversalType::Disbursement, (int) $batch->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        /*
         * The reason the loan is parked in `disbursement_failed` rather than
         * `awaiting_disbursement`: the retry path is open from there, and
         * nothing prepares a batch from the latter. A reversal that stranded
         * the loan would be a reversal nobody could finish.
         *
         * `settled_loan_id` is UNIQUE, so this also proves it was released —
         * a second success is impossible while it is set.
         */
        officerAt('Head Office', RoleName::Finance);

        test()->postJson("/api/v1/loans/{$loan->id}/retry-disbursement")->assertCreated();

        expect($loan->fresh()->status)->toBe(LoanStatus::AwaitingDisbursement);
    });
});

describe('reversing a penalty', function (): void {
    it('waives the uncollected penalty and posts nothing', function (): void {
        $loan = activeLoan();

        $schedule = $loan->schedules()->where('installment_number', 1)->firstOrFail();

        // Past due, so the overdue job has something to penalise.
        test()->travelTo($schedule->due_date->copy()->addDays(10)->startOfDay()->addHours(9));

        officerAt('Head Office', RoleName::Finance);
        test()->postJson('/api/v1/loans/overdue/process')->assertOk();

        $penalised = $schedule->fresh();

        expect($penalised->penaltyDue()->isPositive())->toBeTrue();

        $entriesBefore = App\Models\JournalEntry::query()->count();

        [, $request] = raiseReversal(ReversalType::Penalty, (int) $schedule->getKey(), 'Penalty charged in error');

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $waived = $schedule->fresh();

        expect($waived->outstandingPenalty()->isZero())->toBeTrue()
            // OSC-1: penalty income is recognised on COLLECTION, so an
            // uncollected penalty never reached the books and its reversal
            // must not either.
            ->and(App\Models\JournalEntry::query()->count())->toBe($entriesBefore)
            ->and($request->fresh()->reversal_entry_id)->toBeNull()
            // The waiver's only record, snapshotted before penalty_due was cut.
            ->and(Money::of($request->fresh()->amount)->isPositive())->toBeTrue();
    });

    it('refuses an installment with no penalty on it', function (): void {
        $loan = activeLoan();
        $schedule = $loan->schedules()->where('installment_number', 1)->firstOrFail();

        raiseReversal(ReversalType::Penalty, (int) $schedule->getKey())[0]->assertStatus(409);
    });
});

describe('rejecting', function (): void {
    it('leaves everything untouched', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        $payment = Payment::query()->latest('id')->firstOrFail();

        [, $request] = raiseReversal(ReversalType::Payment, (int) $payment->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/reject", ['note' => 'Payment is correct'])
            ->assertOk();

        expect($request->fresh()->status)->toBe(ReversalStatus::Rejected)
            ->and($request->fresh()->decision_note)->toBe('Payment is correct')
            ->and($payment->fresh()->status)->not->toBe(PaymentStatus::Reversed);
    });

    it('lets the requester withdraw their own', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        [, $request, $requester] = raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        // The same Finance officer who raised it. Nothing moves, so the
        // control that stops self-approval has nothing to protect here.
        actingAsAgain($requester);

        test()->postJson("/api/v1/reversals/{$request->id}/reject", ['note' => 'Raised by mistake'])
            ->assertOk();

        expect($request->fresh()->status)->toBe(ReversalStatus::Rejected);
    });

    it('refuses to decide an already decided request', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        [, $request] = raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertStatus(409);
    });
});

describe('the queue', function (): void {
    it('lists pending requests for an approver', function (): void {
        $loan = matureLoan();
        payCash($loan, installmentTotal($loan)->toDecimalString())->assertCreated();

        raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        actingAsRole(RoleName::Admin);

        test()->getJson('/api/v1/reversals/pending')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.reversalType', 'payment')
            ->assertJsonPath('data.0.status', 'pending');
    });
});

/**
 * Every account's balance, keyed by account — the whole chart, so a reversal
 * that leaves ANY account a shilling off is caught, not only the ones a test
 * thought to name.
 *
 * @return array<int, string>
 */
function chartSnapshot(): array
{
    return App\Models\ChartOfAccount::query()->with('balances')->get()
        ->mapWithKeys(fn (App\Models\ChartOfAccount $a): array => [
            (int) $a->getKey() => $a->cachedBalance()->toDecimalString(),
        ])
        ->all();
}

describe('exact amounts', function (): void {
    it('returns every ledger account to its balance before the repayment', function (): void {
        $loan = matureLoan();

        $before = chartSnapshot();

        payCash($loan, installmentTotal($loan)->add(Money::of('12345.67'))->toDecimalString())->assertCreated();

        [, $request] = raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        expect(chartSnapshot())->toBe($before);
    });

    it('returns the disbursed cash to the exact account it left', function (): void {
        $loan = activeLoan();
        $batch = DisbursementBatch::query()->where('loan_id', $loan->getKey())->firstOrFail();
        $original = $batch->journalEntry()->with('lines')->firstOrFail();

        [, $request] = raiseReversal(ReversalType::Disbursement, (int) $batch->getKey(), 'Paid to the wrong number');

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $mirror = $request->fresh()->reversalEntry()->with('lines')->firstOrFail();

        // Line for line: same account, same amount, opposite side.
        $flip = fn ($lines, bool $swap) => $lines->map(fn ($l): string => sprintf(
            '%d:%s:%s',
            $l->account_id,
            $swap ? $l->creditAmount()->toDecimalString() : $l->debitAmount()->toDecimalString(),
            $swap ? $l->debitAmount()->toDecimalString() : $l->creditAmount()->toDecimalString(),
        ))->sort()->values()->all();

        expect($flip($mirror->lines, true))->toBe($flip($original->lines, false))
            ->and($request->fresh()->amount)->toBe($loan->principal_amount);
    });

    it('takes a collected penalty back off the installment and out of penalty income', function (): void {
        $loan = activeLoan();
        $schedule = $loan->schedules()->where('installment_number', 1)->firstOrFail();

        test()->travelTo($schedule->due_date->copy()->addDays(10)->startOfDay()->addHours(9));

        officerAt('Head Office', RoleName::Finance);
        test()->postJson('/api/v1/loans/overdue/process')->assertOk();

        $penalised = $schedule->fresh();
        $owed = $penalised->outstandingTotal();

        $before = chartSnapshot();

        payCash($loan, $owed->toDecimalString())->assertCreated();

        expect($schedule->fresh()->outstandingPenalty()->isZero())->toBeTrue();

        [, $request] = raiseReversal(ReversalType::Payment, (int) Payment::query()->latest('id')->firstOrFail()->getKey());

        actingAsRole(RoleName::Admin);
        test()->postJson("/api/v1/reversals/{$request->id}/approve")->assertOk();

        $after = $schedule->fresh();

        expect($after->penalty_paid)->toBe('0.00')
            // The charge itself stands — only the collection went back.
            ->and($after->penalty_due)->toBe($penalised->penalty_due)
            ->and($after->outstandingTotal()->toDecimalString())->toBe($owed->toDecimalString())
            ->and(chartSnapshot())->toBe($before);

        // The Paid Penalty register no longer counts a collection that went back.
        $paid = test()->getJson('/api/v1/penalties/paid')->assertOk();

        expect($paid->json('data'))->toBe([])
            ->and($paid->json('meta.totalPaid'))->toBe('0.00');
    });

    it('refuses the payment that settled a loan early', function (): void {
        $loan = matureLoan();

        officerAt($loan->branch->name, RoleName::BranchManager);
        $quote = test()->getJson("/api/v1/loans/{$loan->id}/early-settlement")->assertOk()->json('data');

        test()->postJson("/api/v1/loans/{$loan->id}/early-settlement", ['amount' => $quote['cashRequired']])
            ->assertOk();

        $payment = Payment::query()->findOrFail($loan->fresh()->early_settlement_payment_id);

        raiseReversal(ReversalType::Payment, (int) $payment->getKey())[0]
            ->assertStatus(409)
            ->assertJsonFragment(['message' => sprintf(
                'Payment %s settled loan %s early and cancelled its remaining installments. '
                .'It cannot be reversed on its own — contact the system administrator to restructure the loan.',
                $payment->payment_reference,
                $loan->loan_number,
            )]);
    });
});
