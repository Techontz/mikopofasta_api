<?php

declare(strict_types=1);

namespace App\Domain\Repayments\Actions;

use App\Domain\Repayments\Enums\CashDepositStatus;
use App\Enums\AuditAction;
use App\Models\CashDeposit;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A teller banks the day's cash — the first half of §7's verification loop.
 *
 * The client's description of the branch flow is precise about the order:
 * "Deposits / receives receipts from bank / mobile money → the money goes to
 * some account (ledger) → Finance approves and it goes to a specific place, the
 * customer receives the message and the status changes. For cash, the teller
 * deposits to the required account."
 *
 * This is the teller's step, and it posts nothing. The cash was already
 * ledgered into the branch till when it was taken over the counter; carrying it
 * to the bank does not move it between accounts until the bank confirms
 * receipt. Posting `Dr Bank · Cr Teller Cash` here would record money as banked
 * on a teller's say-so, which is exactly the fraud §7 built two trust states to
 * prevent.
 *
 * The slip goes to the private disk, alongside KYC documents (§1). It is the
 * evidence Finance reconciles against, so it must not be publicly reachable.
 */
final class RecordCashDepositAction
{
    /**
     * Not `public`. §1 puts customer documents on a private disk; a deposit
     * slip carries an account number and a branch's daily takings.
     */
    private const string DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param list<int> $paymentIds the cash payments this deposit covers
     */
    public function handle(
        int $branchId,
        int $bankAccountId,
        Money $amount,
        array $paymentIds,
        ?UploadedFile $slip,
        User $teller,
    ): CashDeposit {
        return DB::transaction(function () use ($branchId, $bankAccountId, $amount, $paymentIds, $slip, $teller): CashDeposit {
            $deposit = CashDeposit::query()->create([
                'teller_id' => $teller->getKey(),
                'branch_id' => $branchId,
                'amount' => $amount->toDecimalString(),
                'bank_account_id' => $bankAccountId,
                'deposit_slip_path' => $slip?->store('deposit-slips', self::DISK),
                'status' => CashDepositStatus::Pending,

                /*
                 * The teller declares which payments this covers. Finance
                 * verifies the declaration rather than trusting it — the
                 * reconciliation refuses if the named payments do not sum to
                 * the amount banked.
                 */
                'matched_payment_ids' => $paymentIds,
            ]);

            $this->audit->log(
                AuditAction::CashDepositRecorded,
                $deposit,
                after: [
                    'branch_id' => $branchId,
                    'bank_account_id' => $bankAccountId,
                    'amount' => $deposit->amount,
                    'payment_ids' => $paymentIds,
                    'has_slip' => $slip !== null,
                ],
                actor: $teller,
            );

            return $deposit->load(['branch', 'bankAccount', 'teller']);
        });
    }

    /** Where a stored slip can be read from. */
    public static function disk(): string
    {
        return self::DISK;
    }

    public static function slipContents(CashDeposit $deposit): ?string
    {
        if ($deposit->deposit_slip_path === null) {
            return null;
        }

        $storage = Storage::disk(self::DISK);

        return $storage->exists($deposit->deposit_slip_path)
            ? $storage->get($deposit->deposit_slip_path)
            : null;
    }
}
