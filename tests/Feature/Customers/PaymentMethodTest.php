<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\PaymentMethod;
use App\Models\Customer;
use App\Models\MasterData\Bank;
use App\Models\MasterData\MobileMoneyProvider;

/**
 * WHICH kind of account the customer gave, kept as an answer.
 *
 * WHAT THIS PREVENTS. The choice between a mobile wallet and a bank account
 * was asked at registration and then thrown away; every screen that needed it
 * rebuilt it by looking at which columns were filled. That reading is wrong in
 * both directions — a bank customer whose account number is later cleared
 * becomes a wallet customer, and a stale wallet number left behind by an edit
 * turns a bank customer into an MNO one. These assert that the stated answer
 * is what is stored and what comes back.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

it('stores the choice the officer stated, not one inferred from the columns', function (): void {
    officerAt();

    $provider = MobileMoneyProvider::query()->firstOrCreate(['code' => 'MPESA'], ['name' => 'M-Pesa']);

    $this->postJson('/api/v1/customers', registrationPayload([
        'bankDetails' => null,
        'paymentMethod' => 'mno',
        'mobileMoneyProviderId' => $provider->id,
        'walletNumber' => '0754000321',
    ]))->assertCreated();

    expect(Customer::query()->latest('id')->first()->payment_method)->toBe(PaymentMethod::Mno);
});

it('reads the choice back on the customer', function (): void {
    officerAt();

    $bank = Bank::query()->firstOrCreate(['code' => 'CRDB'], ['name' => 'CRDB Bank']);

    $id = $this->postJson('/api/v1/customers', registrationPayload([
        'paymentMethod' => 'bank',
        'bankId' => $bank->id,
    ]))->assertCreated()->json('data.id');

    $this->getJson("/api/v1/customers/{$id}")
        ->assertOk()
        ->assertJsonPath('data.paymentMethod', 'bank');
});

it('refuses MNO with no provider or number behind it', function (): void {
    officerAt();

    /* A choice with nothing behind it is what the column exists to make
       impossible; the frontend refuses it too, and this is what makes it a
       rule rather than a convention. */
    $this->postJson('/api/v1/customers', registrationPayload([
        'bankDetails' => null,
        'paymentMethod' => 'mno',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['mobileMoneyProviderId', 'walletNumber']);
});

it('refuses BANK with no bank or account number behind it', function (): void {
    officerAt();

    $this->postJson('/api/v1/customers', registrationPayload([
        'bankDetails' => null,
        'paymentMethod' => 'bank',
    ]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['bankId', 'bankDetails.accountNumber']);
});

it('refuses a method that is neither', function (): void {
    officerAt();

    $this->postJson('/api/v1/customers', registrationPayload(['paymentMethod' => 'cash']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['paymentMethod']);
});

it('leaves it empty when no account was given at all', function (): void {
    officerAt();

    /* Null is a real answer: an account type that requires no account leaves a
       customer with neither, and that is not a gap to be filled with a guess. */
    $this->postJson('/api/v1/customers', registrationPayload(['bankDetails' => null]))
        ->assertCreated();

    expect(Customer::query()->latest('id')->first()->payment_method)->toBeNull();
});

it('derives the method for a client that does not send one', function (): void {
    officerAt();

    /* The column is newer than some callers. Rather than refuse them or leave
       the answer blank, the action falls back to the same reading the
       migration backfilled with — so a record means the same thing however it
       arrived. */
    $payload = registrationPayload(['bankDetails' => null, 'walletNumber' => '0754000999']);
    unset($payload['paymentMethod']);

    $this->postJson('/api/v1/customers', $payload)->assertCreated();

    expect(Customer::query()->latest('id')->first()->payment_method)->toBe(PaymentMethod::Mno);
});
