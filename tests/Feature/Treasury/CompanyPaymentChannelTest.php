<?php

declare(strict_types=1);

use App\Domain\Treasury\Enums\AccountChannelType;
use App\Domain\Treasury\Enums\AccountUsage;
use App\Models\BankAccount;
use App\Models\MasterData\Bank;
use App\Models\MasterData\MobileMoneyProvider;

/**
 * Registered accounts are the company's money CHANNELS — where money is held
 * and through which it moves.
 *
 * Three properties are load-bearing and each has bitten already:
 *
 *   1. The uniqueness keys are DERIVED BY THE DATABASE. An earlier version made
 *      the normalised number an ordinary column and backfilled it, which left
 *      every existing row right and every future insert wrong: any caller that
 *      did not mention it produced the constant key 'bank:0:', and the second
 *      such insert violated the index. That took 680 tests down. The tests here
 *      insert the way a seeder does — naming neither key — precisely so that
 *      cannot come back.
 *
 *   2. There is no branch. A company account belongs to the company.
 *
 *   3. Usage decides which selectors an account appears in, and the account is
 *      registered ONCE regardless of direction.
 */
beforeEach(function (): void {
    seedOrganization();
    test()->seed(Database\Seeders\MasterDataSeeder::class);

    $this->nmb = Bank::query()->firstOrCreate(
        ['code' => 'NMB'],
        ['name' => 'NMB Bank', 'is_active' => true],
    );
    $this->crdb = Bank::query()->firstOrCreate(
        ['code' => 'CRDB'],
        ['name' => 'CRDB Bank', 'is_active' => true],
    );
    $this->mpesa = MobileMoneyProvider::query()->firstOrCreate(
        ['code' => 'MPESA'],
        ['name' => 'M-Pesa', 'is_active' => true],
    );
    $this->airtel = MobileMoneyProvider::query()->firstOrCreate(
        ['code' => 'AIRTELMONEY'],
        ['name' => 'Airtel Money', 'is_active' => true],
    );
});

/** Registers an account the way application code does — never naming a key. */
function channel(array $overrides = []): BankAccount
{
    return BankAccount::query()->create(array_merge([
        'account_type' => AccountChannelType::Bank,
        'usage' => AccountUsage::Both,
        'bank_name' => 'NMB Bank',
        'account_number' => '2011098765400',
        'account_name' => 'Mikopofasta Microfinance Limited',
        'currency' => 'TZS',
        'opening_balance' => '0.00',
        'status' => 'active',
    ], $overrides));
}

// ---------------------------------------------------------------- points 3-5
it('derives both uniqueness keys in the database, with no help from the caller', function (): void {
    $account = channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '2011 0987-654 00']);

    $fresh = $account->fresh();

    expect($fresh->account_number)->toBe('2011 0987-654 00', 'the typed number is stored verbatim')
        ->and($fresh->account_number_key)->toBe('2011098765400', 'punctuation removed by the database')
        ->and($fresh->physical_account_key)->toBe('bank:'.$this->nmb->getKey().':2011098765400');
});

it('derives the keys for a seeder-shaped insert that names no provider at all', function (): void {
    /*
     * THE REGRESSION THAT TOOK 680 TESTS DOWN. ChartOfAccountSeeder inserts
     * bank accounts with no bank_id and no keys. Under the broken design every
     * such row produced the identical key 'bank:0:' and the second one failed.
     */
    channel(['bank_id' => null, 'account_number' => '9990000000001']);
    channel(['bank_id' => null, 'account_number' => '9990000000002']);

    expect(BankAccount::query()->pluck('physical_account_key')->all())
        ->toBe(['bank:0:9990000000001', 'bank:0:9990000000002']);
});

it('has no column any caller must populate for uniqueness to hold', function (): void {
    // `fillable` must not expose the generated columns; assigning one is an error.
    expect((new BankAccount)->getFillable())
        ->not->toContain('account_number_key')
        ->not->toContain('physical_account_key');
});

// ------------------------------------------------------------------ point 6
it('refuses the same physical account however the number is punctuated', function (): void {
    channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '2011098765400']);

    expect(fn () => channel([
        'bank_id' => $this->nmb->getKey(),
        'account_number' => '2011-0987 654 00',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('refuses the same wallet re-registered under the same provider', function (): void {
    channel([
        'account_type' => AccountChannelType::Mno,
        'bank_id' => null,
        'mobile_money_provider_id' => $this->mpesa->getKey(),
        'account_number' => '0754 123 456',
    ]);

    expect(fn () => channel([
        'account_type' => AccountChannelType::Mno,
        'bank_id' => null,
        'mobile_money_provider_id' => $this->mpesa->getKey(),
        'account_number' => '0754-123-456',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

// ---------------------------------------------------------------- points 7-9
it('allows the same digits under different providers', function (): void {
    channel([
        'account_type' => AccountChannelType::Mno,
        'bank_id' => null,
        'mobile_money_provider_id' => $this->mpesa->getKey(),
        'account_number' => '0754123456',
    ]);

    channel([
        'account_type' => AccountChannelType::Mno,
        'bank_id' => null,
        'mobile_money_provider_id' => $this->airtel->getKey(),
        'account_number' => '0754123456',
    ]);

    // Two real wallets that happen to share a number. Both are registrable.
    expect(BankAccount::query()->count())->toBe(2);
});

it('allows the same digits at different banks', function (): void {
    channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '1234567890']);
    channel(['bank_id' => $this->crdb->getKey(), 'account_number' => '1234567890']);

    expect(BankAccount::query()->count())->toBe(2);
});

it('does not collapse provider-less accounts into one key', function (): void {
    /* Point 9. Distinct numbers must stay distinct even with no provider. */
    channel(['bank_id' => null, 'account_number' => '1111111111']);
    channel(['bank_id' => null, 'account_number' => '2222222222']);

    expect(BankAccount::query()->pluck('physical_account_key')->unique())->toHaveCount(2);
});

it('keeps a bank account and a wallet with the same number apart', function (): void {
    channel(['bank_id' => null, 'account_number' => '0754123456']);
    channel([
        'account_type' => AccountChannelType::Mno,
        'bank_id' => null,
        'mobile_money_provider_id' => $this->mpesa->getKey(),
        'account_number' => '0754123456',
    ]);

    expect(BankAccount::query()->count())->toBe(2);
});

// ----------------------------------------------------------------- point 10
it('re-derives the key when the account number is edited', function (): void {
    $account = channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '1111111111']);

    $account->update(['account_number' => '2222 2222 22']);

    expect($account->fresh()->physical_account_key)
        ->toBe('bank:'.$this->nmb->getKey().':2222222222');
});

it('lets an account keep its own number when edited', function (): void {
    $account = channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '1111111111']);

    $account->update(['account_name' => 'Renamed, same account']);

    $fresh = $account->fresh();

    expect($fresh->account_name)->toBe('Renamed, same account')
        ->and($fresh->physical_account_key)->toBe('bank:'.$this->nmb->getKey().':1111111111');
});

it('refuses an edit that would duplicate another account', function (): void {
    channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '1111111111']);
    $second = channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '2222222222']);

    expect(fn () => $second->update(['account_number' => '1111-111-111']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

// ----------------------------------------------------------------- point 11
it('will not let a provider be deleted out from under a registered account', function (): void {
    channel(['bank_id' => $this->nmb->getKey()]);

    /* restrictOnDelete. A bank some account references is deactivated, never
       removed — otherwise the account's identity and its uniqueness key would
       both change underneath the financial records that point at it. */
    expect(fn () => DB::table('banks')->whereKey($this->nmb->getKey())->delete())
        ->toThrow(Illuminate\Database\QueryException::class);
});

// ------------------------------------------------------------- points 1 & 2
it('has no branch on a company money account', function (): void {
    expect(Schema::hasColumn('bank_accounts', 'branch_id'))->toBeFalse();
    expect((new BankAccount)->getFillable())->not->toContain('branch_id');
});

// --------------------------------------------------------------- the usage axis
it('offers only inflow-capable accounts where money comes in', function (): void {
    $collection = channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '1111111111', 'usage' => AccountUsage::Collection]);
    $disbursement = channel(['bank_id' => $this->crdb->getKey(), 'account_number' => '2222222222', 'usage' => AccountUsage::Disbursement]);
    $both = channel(['bank_id' => null, 'account_number' => '3333333333', 'usage' => AccountUsage::Both]);
    $inactive = channel(['bank_id' => null, 'account_number' => '4444444444', 'usage' => AccountUsage::Collection, 'status' => 'inactive']);

    $ids = BankAccount::query()->acceptingInflow()->pluck('id')->all();

    expect($ids)->toContain($collection->id, $both->id)
        ->not->toContain($disbursement->id, 'a disbursement-only account cannot receive')
        ->not->toContain($inactive->id, 'an inactive account is never offered');
});

it('offers only outflow-capable accounts where money goes out', function (): void {
    $collection = channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '1111111111', 'usage' => AccountUsage::Collection]);
    $disbursement = channel(['bank_id' => $this->crdb->getKey(), 'account_number' => '2222222222', 'usage' => AccountUsage::Disbursement]);
    $both = channel(['bank_id' => null, 'account_number' => '3333333333', 'usage' => AccountUsage::Both]);

    $ids = BankAccount::query()->acceptingOutflow()->pluck('id')->all();

    expect($ids)->toContain($disbursement->id, $both->id)
        ->not->toContain($collection->id, 'a collection-only account cannot pay out');
});

it('registers one physical account once, whichever directions it serves', function (): void {
    /*
     * The modelling error `Both` exists to prevent: an "NMB Collection" and an
     * "NMB Disbursement" row for one real bank account would be two balances to
     * reconcile against one statement, and a company total that double-counts.
     */
    channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '2011098765400', 'usage' => AccountUsage::Both]);

    expect(fn () => channel([
        'bank_id' => $this->nmb->getKey(),
        'account_number' => '2011098765400',
        'usage' => AccountUsage::Disbursement,
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('names itself for a selector from master data', function (): void {
    $bank = channel(['bank_id' => $this->nmb->getKey(), 'account_number' => '2011098765400']);
    $wallet = channel([
        'account_type' => AccountChannelType::Mno,
        'bank_id' => null,
        'mobile_money_provider_id' => $this->mpesa->getKey(),
        'account_number' => '0754123456',
        'bank_name' => 'M-Pesa',
    ]);

    expect($bank->load('bank')->channelLabel())->toBe('NMB Bank — 2011098765400')
        ->and($wallet->load('mobileMoneyProvider')->channelLabel())->toBe('M-Pesa — 0754123456');
});

it('falls back to the stored name for an account that names no provider', function (): void {
    $legacy = channel(['bank_id' => null, 'bank_name' => 'Community Bank', 'account_number' => '5555555555']);

    expect($legacy->channelLabel())->toBe('Community Bank — 5555555555');
});
