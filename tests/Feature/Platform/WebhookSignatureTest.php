<?php

declare(strict_types=1);

use App\Models\JournalEntry;
use App\Models\Payment;
use Illuminate\Support\Facades\Config;

/**
 * B2 — §1's HMAC verification: "never trust an unsigned callback".
 *
 * Both webhooks move money — one posts a repayment to the ledger, the other
 * activates a loan — so the signature is the credential and it must be checked
 * before any business logic runs at all.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

describe('the payments webhook', function (): void {
    it('accepts a correctly signed callback', function (): void {
        $loan = activeLoan();
        forgetAuthGuards();

        $signed = signedCallback([
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-SIGNED-1',
        ], paymentsSecret(), 'X-Bank-Signature');

        postSigned('/webhooks/payments', $signed)
            ->assertOk()
            ->assertJsonPath('data.status', 'allocated');

        expect(Payment::query()->where('transaction_id', 'TXN-SIGNED-1')->exists())->toBeTrue();
    });

    it('rejects a missing signature with 401', function (): void {
        $loan = activeLoan();
        forgetAuthGuards();

        $this->postJson('/webhooks/payments', [
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-UNSIGNED',
        ])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'INVALID_WEBHOOK_SIGNATURE');

        expect(Payment::query()->where('transaction_id', 'TXN-UNSIGNED')->exists())->toBeFalse();
    });

    it('rejects an invalid signature with 401', function (): void {
        $loan = activeLoan();
        forgetAuthGuards();

        $signed = signedCallback([
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-BADSIG',
        ], 'the-wrong-secret', 'X-Bank-Signature');

        postSigned('/webhooks/payments', $signed)->assertStatus(401);

        expect(Payment::query()->where('transaction_id', 'TXN-BADSIG')->exists())->toBeFalse();
    });

    it('rejects a payload modified after signing', function (): void {
        $loan = activeLoan();
        forgetAuthGuards();

        $signed = signedCallback([
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-TAMPERED',
        ], paymentsSecret(), 'X-Bank-Signature');

        // The classic attack: keep the valid signature, raise the amount.
        $signed['body'] = str_replace('20000.00', '9000000.00', $signed['body']);

        postSigned('/webhooks/payments', $signed)->assertStatus(401);

        expect(Payment::query()->where('transaction_id', 'TXN-TAMPERED')->exists())->toBeFalse();
    });

    it('rejects a replayed callback outside the timestamp tolerance', function (): void {
        $loan = activeLoan();
        forgetAuthGuards();

        // Correctly signed, but captured an hour ago.
        $signed = signedCallback([
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-REPLAY',
        ], paymentsSecret(), 'X-Bank-Signature', timestamp: time() - 3600);

        postSigned('/webhooks/payments', $signed)->assertStatus(401);

        expect(Payment::query()->where('transaction_id', 'TXN-REPLAY')->exists())->toBeFalse();
    });

    it('rejects everything when the secret is not configured', function (): void {
        Config::set('webhooks.providers.payments.secret', '');

        $loan = activeLoan();
        forgetAuthGuards();

        $signed = signedCallback([
            'reference' => $loan->loan_number,
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-NOSECRET',
        ], paymentsSecret(), 'X-Bank-Signature');

        // A deployment that forgot the secret must fail closed. Treating an
        // empty secret as "verification not required" is how an endpoint that
        // posts to the ledger ends up open to the internet.
        postSigned('/webhooks/payments', $signed)->assertStatus(401);
    });

    it('verifies before any business logic runs', function (): void {
        activeLoan();
        forgetAuthGuards();

        $entriesBefore = JournalEntry::query()->count();
        $paymentsBefore = Payment::query()->count();

        // A payload that would be REJECTED by validation anyway. If validation
        // ran first the response would be 422; a 401 proves the signature is
        // checked before the Form Request, before the controller, before
        // anything is written.
        $this->postJson('/webhooks/payments', ['nonsense' => true])->assertStatus(401);

        expect(JournalEntry::query()->count())->toBe($entriesBefore)
            ->and(Payment::query()->count())->toBe($paymentsBefore);
    });
});

describe('the disbursement webhook', function (): void {
    it('accepts a correctly signed callback and activates the loan', function (): void {
        $loan = loanAtFinance();

        officerAt('Head Office', App\Domain\Auth\Enums\RoleName::Finance);
        $reference = $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])
            ->assertCreated()->json('data.batchReference');

        forgetAuthGuards();

        $signed = signedCallback(
            ['batchReference' => $reference, 'success' => true],
            vodacomSecret(),
            'X-Vodacom-Signature',
        );

        postSigned('/webhooks/vodacom/disbursement-status', $signed)->assertOk();

        expect($loan->fresh()->status)->toBe(App\Domain\Loans\Enums\LoanStatus::Active);
    });

    it('rejects an unsigned callback and leaves the loan unfunded', function (): void {
        $loan = loanAtFinance();

        officerAt('Head Office', App\Domain\Auth\Enums\RoleName::Finance);
        $reference = $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])
            ->assertCreated()->json('data.batchReference');

        forgetAuthGuards();

        $this->postJson('/webhooks/vodacom/disbursement-status', [
            'batchReference' => $reference,
            'success' => true,
        ])->assertStatus(401);

        // The whole point: an unsigned caller cannot activate a loan or post
        // its disbursement entry.
        expect($loan->fresh()->status)->toBe(App\Domain\Loans\Enums\LoanStatus::AwaitingDisbursement)
            ->and(JournalEntry::query()->where('source_id', $loan->getKey())->exists())->toBeFalse();
    });

    it('rejects a signature made with the other provider secret', function (): void {
        $loan = loanAtFinance();

        officerAt('Head Office', App\Domain\Auth\Enums\RoleName::Finance);
        $reference = $this->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])
            ->assertCreated()->json('data.batchReference');

        forgetAuthGuards();

        // Each provider has its own secret; one cannot sign for another.
        $signed = signedCallback(
            ['batchReference' => $reference, 'success' => true],
            paymentsSecret(),
            'X-Vodacom-Signature',
        );

        postSigned('/webhooks/vodacom/disbursement-status', $signed)->assertStatus(401);

        expect($loan->fresh()->status)->toBe(App\Domain\Loans\Enums\LoanStatus::AwaitingDisbursement);
    });
});

describe('what a rejection reveals', function (): void {
    it('gives the same body whatever the reason', function (): void {
        activeLoan();
        forgetAuthGuards();

        $missing = $this->postJson('/webhooks/payments', ['reference' => 'x'])->assertStatus(401);

        $wrong = postSigned('/webhooks/payments', signedCallback(
            ['reference' => 'x'],
            'wrong',
            'X-Bank-Signature',
        ))->assertStatus(401);

        // Distinguishing "missing" from "mismatched" would tell an attacker
        // which half of the check they had passed.
        expect($missing->json())->toBe($wrong->json());
    });
});
