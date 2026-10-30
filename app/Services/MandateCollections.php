<?php

namespace App\Services;

use App\Enums\LoanStatus;
use App\Integrations\Payments\PaymentNotification;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanMandate;
use App\Models\MandateCollection;
use App\Models\MandateCollectionBatch;
use App\Models\PaymentAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * E-Mandate collections (enhancement §4.2–4.3). Every debit the bank reports — from an uploaded collection file or a
 * provider notification — is recorded once per provider payment reference (a repeat returns the first record, so nothing is
 * allocated twice). Money received goes through the normal payment intake ({@see PaymentService::receive()}) and therefore
 * the existing allocation order (what is due first — previous arrears, then the current instalment — then the rest);
 * anything beyond the debt is held in suspense as usual.
 *
 * The payment status is never the provider's word alone: Full when the amount allocated covers what was due on the
 * collection date (arrears included), Partial when it covers less, Failed when the bank did not collect, Unmatched when no
 * mandate of the company carries the reference.
 */
class MandateCollections
{
    /** Payment channel of mandate debits; other channels cannot repay an E-Mandate loan. */
    public const CHANNEL = 'mandate';

    /**
     * @var list<string>
     */
    public const SUCCESS_STATUSES = ['SUCCESS', 'SUCCESSFUL', 'PAID', 'COMPLETED', 'COLLECTED'];

    public function __construct(
        private PaymentService $payments,
        private LoanService $loans,
    ) {}

    /**
     * Whether the loan is repaid through an active bank mandate (an E-Mandate loan method).
     */
    public static function collectsByMandate(Loan $loan): bool
    {
        return LoanMandate::where('loan_id', $loan->id)->where('status', LoanMandate::ACTIVE)->whereNull('cancelled_at')->exists();
    }

    /**
     * @param  array{mandate_reference?: string|null, payment_reference: string, amount: float|int|string, raw_status: string, collection_date?: string|null, remarks?: string|null, channel?: string|null, mandate_type?: string|null}  $row
     * @param  array<string, mixed>  $payload
     */
    public function record(int $companyId, string $provider, array $row, ?MandateCollectionBatch $batch = null, array $payload = []): MandateCollection
    {
        $reference = trim((string) $row['payment_reference']);
        if ($reference === '') {
            throw ValidationException::withMessages(['payment_reference' => 'Every collection needs the bank payment reference.']);
        }

        $existing = MandateCollection::where('company_id', $companyId)->where('provider', $provider)->where('payment_reference', $reference)->first();
        if ($existing !== null) {
            return $existing;
        }

        $mandate = filled($row['mandate_reference'] ?? null)
            ? LoanMandate::query()->where('company_id', $companyId)->where('mandate_reference', trim((string) $row['mandate_reference']))->latest('id')->first()
            : null;
        $loan = $mandate?->loan;
        $date = CarbonImmutable::parse($row['collection_date'] ?? $batch?->collection_date ?? today())->startOfDay();
        $amount = round((float) str_replace(',', '', (string) $row['amount']), 2);
        $collected = in_array(strtoupper(trim($row['raw_status'])), self::SUCCESS_STATUSES, true) && $amount > 0;
        $expected = $loan ? $this->dueOn($loan, $date) : 0.0;

        return DB::transaction(function () use ($companyId, $provider, $row, $batch, $payload, $reference, $mandate, $loan, $date, $amount, $collected, $expected): MandateCollection {
            $paymentId = null;
            $allocated = 0.0;
            if ($collected && $loan !== null) {
                $result = $this->payments->receive(new PaymentNotification(
                    transactionId: $provider.':'.$reference,
                    amount: $amount,
                    channel: self::CHANNEL,
                    reference: $loan->reference_number ?? $loan->loan_number,
                    paidOn: $date->toDateString(),
                    payload: ['mandate_collection' => $row] + $payload,
                ));
                $paymentId = $result['payment']->id;
                $allocated = round((float) PaymentAllocation::where('payment_id', $paymentId)->sum('amount'), 2);
            }

            $status = match (true) {
                $loan === null => MandateCollection::UNMATCHED,
                ! $collected => MandateCollection::FAILED,
                $allocated + 0.5 >= $expected => MandateCollection::FULL,
                default => MandateCollection::PARTIAL,
            };

            $collection = MandateCollection::create([
                'company_id' => $companyId,
                'branch_id' => $loan?->branch_id,
                'batch_id' => $batch?->id,
                'loan_mandate_id' => $mandate?->id,
                'loan_id' => $loan?->id,
                'payment_id' => $paymentId,
                'provider' => $provider,
                'channel' => $row['channel'] ?? $mandate?->channel ?? $batch?->channel,
                'mandate_type' => $row['mandate_type'] ?? $mandate?->mandate_type,
                'mandate_reference' => $row['mandate_reference'] ?? null,
                'payment_reference' => $reference,
                'raw_status' => mb_substr(trim($row['raw_status']), 0, 60),
                'payment_status' => $status,
                'expected_amount' => $expected,
                'paid_amount' => $collected ? $amount : 0,
                'allocated_amount' => $allocated,
                'collection_date' => $date->toDateString(),
                'remarks' => isset($row['remarks']) ? mb_substr((string) $row['remarks'], 0, 500) : null,
                'payload' => $payload ?: null,
            ]);

            if ($batch !== null) {
                $batch->increment('record_count');
                $batch->increment('expected_total', $expected);
                $batch->increment('paid_total', $collected ? $amount : 0);
            }

            return $collection;
        });
    }

    /**
     * Import a bank collection file (CSV: mandate_reference, payment_reference, amount, status, collection_date, remarks).
     *
     * @return array{batch: MandateCollectionBatch, recorded: int, repeated: int}
     */
    public function importFile(int $companyId, string $provider, ?string $channel, string $collectionDate, string $path, Employee $actor): array
    {
        $handle = fopen($path, 'r');
        $header = array_map(fn ($cell): string => Str::snake(strtolower(trim((string) $cell))), fgetcsv($handle) ?: []);
        foreach (['mandate_reference', 'payment_reference', 'amount', 'status'] as $required) {
            if (! in_array($required, $header, true)) {
                fclose($handle);
                throw ValidationException::withMessages(['file' => "The collection file needs a {$required} column."]);
            }
        }

        $batch = MandateCollectionBatch::create([
            'company_id' => $companyId,
            'reference' => 'MCB-'.now()->format('ymdHis').'-'.Str::upper(Str::random(4)),
            'provider' => $provider,
            'channel' => $channel,
            'source' => 'file',
            'collection_date' => $collectionDate,
            'uploaded_by' => $actor->id,
        ]);

        $recorded = 0;
        $repeated = 0;
        while (($cells = fgetcsv($handle)) !== false) {
            if (count(array_filter($cells, fn ($cell): bool => trim((string) $cell) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), null));
            $collection = $this->record($companyId, $provider, [
                'mandate_reference' => $row['mandate_reference'],
                'payment_reference' => (string) $row['payment_reference'],
                'amount' => $row['amount'] ?? 0,
                'raw_status' => (string) $row['status'],
                'collection_date' => filled($row['collection_date'] ?? null) ? $row['collection_date'] : $collectionDate,
                'remarks' => $row['remarks'] ?? null,
                'channel' => $channel,
            ], $batch);
            $collection->batch_id === $batch->id ? $recorded++ : $repeated++;
        }
        fclose($handle);

        return ['batch' => $batch->refresh(), 'recorded' => $recorded, 'repeated' => $repeated];
    }

    /**
     * What the loan owed on that day: every instalment due by then, less what was paid (arrears and the current one).
     */
    public function dueOn(Loan $loan, CarbonImmutable $date): float
    {
        if (! in_array($loan->status, [...LoanStatus::repayable(), LoanStatus::Closed], true)) {
            return 0.0;
        }

        return round((float) $loan->schedules()->whereDate('due_date', '<=', $date->toDateString())
            ->selectRaw('COALESCE(SUM(GREATEST(amount - paid_amount, 0)), 0) as due')->value('due'), 2);
    }

    /**
     * Ask for the cancellation of a mandate replaced by a top-up (enhancement §3.4). It stays requested — never cancelled —
     * until the bank's confirmation reference is recorded; the mandate's history is kept.
     */
    public function requestCancellation(LoanMandate $mandate, ?Employee $actor): LoanMandate
    {
        if ($mandate->cancellation_status === null) {
            $mandate->forceFill([
                'cancellation_status' => LoanMandate::CANCELLATION_REQUESTED,
                'cancellation_requested_at' => now(),
                'cancellation_requested_by' => $actor?->id,
            ])->save();
        }

        return $mandate;
    }

    /**
     * Record the bank's confirmation of a cancellation (Finance, with the bank's reference).
     */
    public function confirmCancellation(LoanMandate $mandate, string $providerReference): LoanMandate
    {
        if ($mandate->cancellation_status !== LoanMandate::CANCELLATION_REQUESTED) {
            throw ValidationException::withMessages(['mandate' => 'Only a mandate whose cancellation was requested can be confirmed cancelled.']);
        }

        $mandate->forceFill([
            'cancellation_status' => LoanMandate::CANCELLATION_CONFIRMED,
            'cancellation_reference' => mb_substr(trim($providerReference), 0, 100),
            'cancelled_at' => now(),
        ])->save();

        return $mandate;
    }

    /**
     * E-Mandate reports (enhancement §4.4) for the branch scope and the period; totals follow the same filters.
     *
     * @param  list<int>|null  $branchIds
     * @return array<string, mixed>
     */
    public function report(int $companyId, ?array $branchIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $mandates = fn () => LoanMandate::query()->where('loan_mandates.company_id', $companyId)
            ->join('loans', 'loans.id', '=', 'loan_mandates.loan_id')
            ->when($branchIds !== null, fn ($query) => $query->whereIn('loans.branch_id', $branchIds));
        $collections = fn () => MandateCollection::query()->where('mandate_collections.company_id', $companyId)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('mandate_collections.branch_id', $branchIds))
            ->whereBetween('collection_date', [$from->toDateString(), $to->toDateString()]);
        $mandateLoans = fn () => Loan::query()->where('loans.company_id', $companyId)
            ->when($branchIds !== null, fn ($query) => $query->whereIn('loans.branch_id', $branchIds))
            ->whereIn('loans.status', LoanStatus::values(...LoanStatus::repayable()))
            ->whereExists(fn ($query) => $query->from('loan_mandates')->whereColumn('loan_mandates.loan_id', 'loans.id')->where('loan_mandates.status', LoanMandate::ACTIVE)->whereNull('loan_mandates.cancelled_at'));

        $expected = (float) DB::table('loan_schedules')->whereIn('loan_id', $mandateLoans()->select('loans.id'))
            ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])->sum('amount');
        $outstanding = $mandateLoans()->get()->sum(fn (Loan $loan): float => $this->loans->outstanding($loan)['total']);

        $paid = $collections()->with(['loan:id,loan_number', 'branch:id,name'])->latest('collection_date')->latest('id')->get();

        return [
            'cards' => [
                'total_mandates' => $mandates()->count(),
                'approved_mandates' => $mandates()->where('loan_mandates.status', LoanMandate::ACTIVE)->count(),
                'pending_mandates' => $mandates()->where('loan_mandates.status', LoanMandate::PENDING_OTP)->count(),
                'failed_mandates' => $mandates()->where('loan_mandates.status', LoanMandate::FAILED)->count(),
                'expected_this_period' => round($expected, 2),
                'paid_this_period' => round((float) $paid->sum('allocated_amount'), 2),
                'total_outstanding' => round($outstanding, 2),
            ],
            'channel_performance' => $paid->groupBy(fn (MandateCollection $row): string => $row->channel ?? 'UNKNOWN')
                ->map(fn ($rows, string $channel): array => ['channel' => $channel, 'count' => $rows->count(), 'expected' => round((float) $rows->sum('expected_amount'), 2), 'paid' => round((float) $rows->sum('allocated_amount'), 2)])->values()->all(),
            'branch_performance' => $paid->groupBy(fn (MandateCollection $row): string => $row->branch?->name ?? 'UNMATCHED')
                ->map(fn ($rows, string $branch): array => ['branch' => $branch, 'count' => $rows->count(), 'expected' => round((float) $rows->sum('expected_amount'), 2), 'paid' => round((float) $rows->sum('allocated_amount'), 2)])->values()->all(),
            'paid_rows' => $paid->map(fn (MandateCollection $row): array => [
                'id' => $row->id,
                'provider' => $row->provider,
                'channel' => $row->channel,
                'mandate_type' => $row->mandate_type,
                'mandate_reference' => $row->mandate_reference,
                'payment_reference' => $row->payment_reference,
                'raw_status' => $row->raw_status,
                'payment_status' => $row->payment_status,
                'expected_amount' => (float) $row->expected_amount,
                'paid_amount' => (float) $row->paid_amount,
                'allocated_amount' => (float) $row->allocated_amount,
                'collection_date' => $row->collection_date?->toDateString(),
                'remarks' => $row->remarks,
                'loan_number' => $row->loan?->loan_number,
                'branch' => $row->branch?->name,
            ])->all(),
            'batches' => MandateCollectionBatch::query()->where('company_id', $companyId)
                ->whereBetween('collection_date', [$from->toDateString(), $to->toDateString()])->latest('id')->limit(50)->get()
                ->map(fn (MandateCollectionBatch $batch): array => [
                    'id' => $batch->id, 'reference' => $batch->reference, 'provider' => $batch->provider, 'channel' => $batch->channel,
                    'source' => $batch->source, 'collection_date' => $batch->collection_date?->toDateString(), 'records' => $batch->record_count,
                    'expected' => (float) $batch->expected_total, 'paid' => (float) $batch->paid_total,
                ])->all(),
        ];
    }
}
