<?php

namespace Database\Seeders\DevSeed;

use App\Enums\LoanStatus;
use App\Enums\PaymentStatus;
use App\Integrations\Payments\TestPaymentWebhookConnector;
use App\Models\Loan;
use App\Models\Payment;
use App\Models\TellerDeposit;
use App\Services\LoanService;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Loans through the full workflow (apply → branch manager → e-mandate + OTP → credit officer with Vodacom KYC → finance
 * prepare / disburse), repayments by teller cash (receipt → bank slip → finance verify + confirm) and M-Pesa webhook, the
 * daily overdue job for past dates, a top-up and a write-off.
 */
final class LoanPhase
{
    /** @var array<string, string> */
    private const REASONS = [
        'WAJ_W' => 'Kuongeza mtaji wa biashara', 'WAJ_M' => 'Kununua mzigo wa jumla', 'MTU' => 'Ujenzi wa nyumba', 'BIN' => 'Ada ya shule ya watoto',
        'B30' => 'Mahitaji ya dharura ya familia', 'STU' => 'Ada ya muhula wa chuo', 'PEN' => 'Kilimo cha mahindi na maharage',
    ];

    /** @var array<string, string> */
    private const SLIP_BANK = ['KK' => 'NMB', 'MS' => 'NMB', 'LN' => 'NMB', 'KB' => 'NMB', 'KS' => 'NMB', 'KM' => 'CRDB', 'BH' => 'CRDB', 'UV' => 'CRDB'];

    /** @var array<string, true> */
    private array $slips = [];

    public function __construct(private readonly Context $ctx) {}

    public function register(): void
    {
        foreach (Catalog::loans() as $index => $spec) {
            $this->loan($spec, $index);
        }

        for ($day = CarbonImmutable::parse('2026-04-07'); $day->lt(CarbonImmutable::parse('2026-09-14')); $day = $day->addDay()) {
            $date = $day->toDateString();
            $this->ctx->timeline->at($day->setTime(23, 50), "overdue job {$date}", fn () => $this->overdueJob($date), fn (): bool => $this->ctx->historyComplete);
        }
        $this->ctx->timeline->at('2026-09-14 06:00', 'overdue job 2026-09-14', fn () => $this->overdueJob('2026-09-14'));
    }

    private function overdueJob(string $date): void
    {
        Artisan::call('loans:process-overdue', ['--date' => $date]);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function loan(array $spec, int $index): void
    {
        $code = $spec['code'];
        $branch = $spec['branch'];
        $stop = $spec['stop'] ?? 'full';
        $definition = Catalog::LOAN_CATEGORIES[$spec['cat']];
        $apply = CarbonImmutable::parse($spec['apply'])->setTime(9, 0)->addMinutes($index % 50);
        $loan = fn (): ?Loan => $this->ctx->loan($code);
        $status = fn (): ?LoanStatus => $loan()?->status;
        $label = "loan {$code}";

        $this->ctx->timeline->at($apply, "{$label} apply", function () use ($spec, $code, $branch, $definition): void {
            $customer = $this->ctx->customer(CustomerPhase::phoneForLoan($code)) ?? throw new RuntimeException("Customer of {$code} is missing");
            $category = $this->ctx->category($spec['cat']);

            $this->ctx->api->call($this->ctx->branchActor($branch, 'loan_officer'), 'POST', 'loans', [
                'customer_id' => $customer->id,
                'category_id' => $category->id,
                'how_loan' => $spec['amount'],
                'day' => $definition['duration'],
                'session' => $spec['sessions'],
                'rate' => $definition['formula'],
                'fee_status' => $definition['fee_deduct'],
                'reason' => self::REASONS[$spec['cat']].' '.Context::marker($code),
            ]);
        }, fn (): bool => $loan() !== null);

        if ($stop === 'apply') {
            return;
        }

        $manager = fn () => $this->ctx->branchActor($branch, 'branch_manager');
        $pendingManager = fn (): bool => $loan() !== null && $status() !== LoanStatus::PendingManagerApproval;

        if ($stop === 'reject_manager' || $stop === 'return') {
            $this->ctx->timeline->at($apply->setTime(11, 30), "{$label} manager ".($stop === 'return' ? 'returns' : 'rejects'), function () use ($loan, $manager, $stop): void {
                $this->ctx->api->call($manager(), 'POST', "loans/{$loan()->id}/".($stop === 'return' ? 'modify' : 'reject'), [
                    'reason' => $stop === 'return' ? 'Rekebisha idadi ya marejesho iendane na muda wa masomo.' : 'Mdhamini hana uwezo wa kulipa ada iliyobaki.',
                ]);
            }, $pendingManager);

            return;
        }

        $this->ctx->timeline->at($apply->setTime(11, 0)->addMinutes($index % 50), "{$label} manager approves", function () use ($loan, $manager, $spec): void {
            $this->ctx->api->call($manager(), 'POST', "loans/{$loan()->id}/approve-manager", ['loan_aprove' => $spec['amount']]);
        }, $pendingManager);

        if ($definition['mandate'] === 'YES') {
            if ($stop === 'manager') {
                return;
            }
            $this->ctx->timeline->at($apply->setTime(12, 0)->addMinutes($index % 50), "{$label} e-mandate", function () use ($loan, $branch): void {
                $customer = $loan()->customer;
                $this->ctx->api->call($this->ctx->branchActor($branch, 'loan_officer'), 'POST', "loans/{$loan()->id}/e-mandate", [
                    'bank_name' => $customer->bank_name,
                    'account_number' => $customer->account_number,
                    'account_name' => $customer->account_name,
                ]);
                $this->ctx->api->call($this->ctx->branchActor($branch, 'loan_officer'), 'POST', "loans/{$loan()->id}/e-mandate/verify-otp", ['otp' => '123456']);
            }, fn (): bool => $loan() !== null && ! in_array($status(), [LoanStatus::PendingManagerApproval, LoanStatus::MandatePendingOtp], true));
        } elseif ($stop === 'manager') {
            return;
        }

        // The customer fills and signs the generated agreement; the loan officer uploads it before credit review.
        $this->ctx->timeline->at($apply->setTime(13, 0)->addMinutes($index % 50), "{$label} signed agreement uploaded", function () use ($loan, $branch): void {
            $this->ctx->api->call($this->ctx->branchActor($branch, 'loan_officer'), 'POST', "loans/{$loan()->id}/agreement", [], ['attach' => Images::signedAgreement()]);
        }, fn (): bool => $loan()?->agreement_file !== null);

        $creditOfficer = fn () => $this->ctx->staff($index % 2 === 0 ? 'HQ_CR1' : 'HQ_CR2');
        $this->ctx->timeline->at($apply->setTime(14, 0)->addMinutes($index % 50), "{$label} credit review", function () use ($loan, $creditOfficer, $stop): void {
            $verification = $this->ctx->api->call($creditOfficer(), 'POST', "loans/{$loan()->id}/kyc-verify");
            if (($verification['verification']['matched'] ?? false) !== true) {
                throw new RuntimeException('Vodacom KYC did not match: '.json_encode($verification['verification'] ?? null));
            }
            if ($stop !== 'reject_credit') {
                $this->ctx->api->call($creditOfficer(), 'POST', "loans/{$loan()->id}/approve-credit");
            }
        }, fn (): bool => $loan()?->telco_verified_at !== null && ($stop === 'reject_credit' || $status() !== LoanStatus::PendingCreditReview));

        if ($stop === 'reject_credit') {
            $this->ctx->timeline->at($apply->addDay()->setTime(10, 0), "{$label} credit officer rejects", function () use ($loan, $creditOfficer): void {
                $this->ctx->api->call($creditOfficer(), 'POST', "loans/{$loan()->id}/reject", ['reason' => 'Chuo hakijathibitisha usajili wa mwanafunzi kwa muhula huu.']);
            }, fn (): bool => $loan() !== null && $status() === LoanStatus::Rejected);

            return;
        }
        if ($stop === 'credit') {
            return;
        }

        $withdraw = $apply->addDay();
        $source = ($spec['source'] ?? 'cash') === 'cash' ? ['source_account' => 'cash'] : ['source_account' => 'bank', 'source_bank_account_id' => null];
        $this->ctx->timeline->at($withdraw->setTime(9, 30)->addMinutes($index % 25), "{$label} finance prepares", function () use ($loan, $source, $spec): void {
            if ($source['source_account'] === 'bank') {
                $source['source_bank_account_id'] = $this->ctx->bank($spec['source'])->id;
            }
            $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "loans/{$loan()->id}/prepare-disbursement", $source);
        }, fn (): bool => $loan() !== null && ! in_array($status(), [LoanStatus::PendingFinance], true));

        if ($stop === 'prepare') {
            return;
        }

        $this->ctx->timeline->at($withdraw->setTime(10, 0)->addMinutes($index % 25), "{$label} disbursed", function () use ($loan): void {
            $this->ctx->api->call($this->ctx->staff('HQ_FIN1'), 'POST', "loans/{$loan()->id}/disburse");
            if ($loan()->status !== LoanStatus::Active) {
                throw new RuntimeException('Disbursement did not activate the loan: '.$loan()->status->value);
            }
        }, fn (): bool => $loan() !== null && $loan()->withdrawn_at !== null);

        $this->repayments($spec, $index, $withdraw, $loan);

        if (isset($spec['writeoff'])) {
            $this->ctx->timeline->at(CarbonImmutable::parse($spec['writeoff'])->setTime(11, 0), "{$label} written off", function () use ($loan): void {
                $this->ctx->api->call($this->ctx->demo('super_admin'), 'POST', "loans/{$loan()->id}/write-off");
            }, fn (): bool => $status() === LoanStatus::WrittenOff);
        }
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  \Closure(): ?Loan  $loan
     */
    private function repayments(array $spec, int $index, CarbonImmutable $withdraw, \Closure $loan): void
    {
        $period = Catalog::LOAN_CATEGORIES[$spec['cat']]['duration'] === 'weekly' ? 7 : 30;
        $payments = [];

        foreach ($spec['plan'] ?? [] as $item) {
            $parts = explode(':', $item);
            match ($parts[0]) {
                'due' => (function () use ($parts, $period, $withdraw, $spec, &$payments): void {
                    [$from, $to] = array_map('intval', explode('-', $parts[1]));
                    for ($k = $from; $k <= $to; $k++) {
                        $payments[] = [$withdraw->addDays($period * $k), $k === $spec['sessions'] ? 'full' : 'inst'];
                    }
                })(),
                'late' => $payments[] = [$withdraw->addDays($period * (int) $parts[1] + (int) $parts[2]), (int) $parts[1] === $spec['sessions'] ? 'full' : 'inst'],
                'amt' => $payments[] = [$withdraw->addDays((int) $parts[1]), (float) $parts[2]],
                'full' => $payments[] = [$withdraw->addDays((int) $parts[1]), 'full'],
            };
        }

        foreach ($payments as $number => [$date, $amount]) {
            $channel = $spec['channel'] ?? 'cash';
            $label = "loan {$spec['code']} repayment ".($number + 1)." ({$channel})";

            if ($channel === 'mpesa') {
                $transactionId = Catalog::MARKER."-MP-{$spec['code']}-".($number + 1);
                $this->ctx->timeline->at($date->setTime(12, 0)->addMinutes($index % 50), $label, function () use ($loan, $amount, $transactionId, $date): void {
                    $this->mpesa($loan(), $amount, $transactionId, $date);
                }, fn (): bool => Payment::where('transaction_id', $transactionId)->exists());

                continue;
            }

            $this->ctx->timeline->at($date->setTime(11, 0)->addMinutes($index % 50), $label, function () use ($loan, $amount, $spec): void {
                $current = $loan();
                $this->ctx->api->call($this->ctx->branchActor($spec['branch'], 'teller'), 'POST', "teller/customers/{$current->customer_id}/deposit", [
                    'depost' => $this->amount($current, $amount),
                    'p_method' => 'CASH',
                    'recept' => true,
                ]);
            }, fn (): bool => ($current = $loan()) !== null && Payment::where('loan_id', $current->id)->where('source', Payment::SOURCE_TELLER)->whereDate('paid_on', $date->toDateString())->exists());

            $this->slip($spec['branch'], $date);
        }
    }

    /**
     * One bank slip per branch per day for the day's DEV teller receipts, verified and confirmed by Finance (allocation).
     */
    private function slip(string $branch, CarbonImmutable $date): void
    {
        $slipNumber = Catalog::MARKER."-SLIP-{$branch}-{$date->format('Ymd')}";
        if (isset($this->slips[$slipNumber])) {
            return;
        }
        $this->slips[$slipNumber] = true;

        $this->ctx->timeline->at($date->setTime(16, 30), "bank slip {$slipNumber}", function () use ($branch, $date, $slipNumber): void {
            $payments = Payment::where('company_id', $this->ctx->company->id)
                ->where('branch_id', $this->ctx->branch($branch)->id)
                ->where('source', Payment::SOURCE_TELLER)
                ->where('status', PaymentStatus::PendingVerification->value)
                ->whereDate('paid_on', $date->toDateString())
                ->whereHas('loan', fn ($query) => $query->where('reason', 'like', '%['.Catalog::MARKER.'-%'))
                ->get();
            if ($payments->isEmpty()) {
                return;
            }

            $total = round((float) $payments->sum('amount'), 2);
            $bank = $this->ctx->bank(self::SLIP_BANK[$branch]);
            $finance = $this->ctx->staff('HQ_FIN1');

            $this->ctx->api->call($this->ctx->branchActor($branch, 'teller'), 'POST', 'teller/bank-deposits', [
                'bank_account_id' => $bank->id,
                'slip_number' => $slipNumber,
                'amount' => $total,
                'deposit_date' => $date->toDateString(),
                'payment_ids' => $payments->pluck('id')->all(),
            ]);
            $deposit = TellerDeposit::where('slip_number', $slipNumber)->firstOrFail();
            $this->ctx->api->call($finance, 'POST', "payments/reconciliation/{$deposit->id}/verify", ['statement_amount' => $total, 'statement_reference' => "{$bank->name}-STMT-{$date->format('Ymd')}-{$branch}"]);
            $this->ctx->api->call($finance, 'POST', "payments/reconciliation/{$deposit->id}/confirm");
        }, fn (): bool => TellerDeposit::where('slip_number', $slipNumber)->where('status', TellerDeposit::STATUS_CONFIRMED)->exists());
    }

    private function mpesa(Loan $loan, string|float $amount, string $transactionId, CarbonImmutable $date): void
    {
        $body = [
            'transaction_id' => $transactionId,
            'amount' => $this->amount($loan, $amount),
            'channel' => 'MPESA',
            'reference' => $loan->reference_number ?? $loan->loan_number,
            'phone' => $loan->customer->phone,
            'paid_on' => $date->toDateString(),
        ];
        $secret = config('integrations.payments.webhook_secret') ?: TestPaymentWebhookConnector::DEFAULT_SECRET;
        $signature = hash_hmac('sha256', json_encode($body, JSON_THROW_ON_ERROR), $secret);

        $result = $this->ctx->api->call(null, 'POST', '/api/webhooks/payments', $body, [], ['X-Signature' => $signature]);
        if (($result['status'] ?? null) !== 'PAYMENT_SUCCESS') {
            throw new RuntimeException('M-Pesa payment was not allocated: '.json_encode($result));
        }
    }

    /**
     * Instalment (restoration), the whole outstanding balance, or a fixed amount — never more than what is still owed.
     */
    private function amount(Loan $loan, string|float $spec): float
    {
        $loan->refresh();
        $available = round(app(LoanService::class)->outstanding($loan)['total'] - app(PaymentService::class)->pendingCash($loan), 2);
        if ($available <= 0) {
            throw new RuntimeException("Loan {$loan->loan_number} has nothing outstanding");
        }

        $amount = match (true) {
            $spec === 'full' => $available,
            $spec === 'inst' => min(ceil((float) $loan->restoration), $available),
            default => min((float) $spec, $available),
        };

        return $available - $amount < 100 ? $available : $amount;
    }
}
