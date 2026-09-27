<?php

namespace App\Services\Shares;

use App\Enums\ShareTransactionType;
use App\Models\ApprovalPolicy;
use App\Models\Capital;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Models\ShareIssuanceRequest;
use App\Models\ShareStructure;
use App\Models\ShareTransaction;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\CapitalContributions;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Share structure set-up (initial allocation) and new share issuance — the only ways total issued shares grow.
 *
 * Accounting treatment (the existing ledger, no second engine):
 *  - paid: the subscription amount is recorded through {@see CapitalContributions}: one `capitals` row and one journal
 *    Dr COMPANY ACCOUNT (cash) or the receiving bank / Cr CAPITAL ACCOUNT (share capital); the share transaction links
 *    to that contribution and journal entry.
 *  - linked_contribution: the money was already recorded as a capital contribution; the share transaction links to it
 *    and no second journal is posted.
 *  - no_cash: founders' allocation without payment or an explicit bonus issuance — no journal.
 *
 * C6 maker/checker: a PAID issuance from Shares → Issue Shares is only requested ({@see self::requestIssuance()}: a
 * `share_issuance_requests` row — no contribution, journal or share transaction). A different authorised user approves it
 * ({@see self::approveIssuance()}, which runs {@see self::issue()} dated the approval date) or rejects it. {@see self::issue()}
 * with the paid treatment posts immediately and is for that approval (and internal callers) only. The paid treatment of the
 * one-time initial allocation ({@see self::establish()}) is unchanged.
 */
class ShareIssuance
{
    public function __construct(
        private readonly ShareRegister $register,
        private readonly ShareValuations $valuations,
        private readonly CapitalContributions $contributions,
        private readonly SegregationOfDuties $duties,
    ) {}

    /**
     * Request a PAID share issuance (C6): validates the structure, price, authorised limit and payment, stores the optional
     * receipt / document privately and records the request as PENDING. Nothing is posted and ownership does not change. A
     * repeated idempotency key returns the original request.
     *
     * @param  array{pay_method?: string|null, bank_account_id?: int|null, receipt_number?: string|null, cheque_number?: string|null}  $payment
     * @return array{request: ShareIssuanceRequest, created: bool}
     *
     * @throws ValidationException
     */
    public function requestIssuance(
        ShareHolder $holder,
        int $shares,
        ?float $pricePerShare,
        ?CarbonInterface $date,
        array $payment,
        ?string $notes,
        Employee $requestedBy,
        ?string $idempotencyKey = null,
        ?UploadedFile $document = null,
    ): array {
        $companyId = (int) $holder->company_id;

        $previous = $this->replayedRequest($idempotencyKey, $holder, $shares);
        if ($previous !== null) {
            return ['request' => $previous, 'created' => false];
        }

        $structure = $this->register->requireStructure($companyId);
        $at = $this->register->moment($date, $structure, 'issue_date');
        $price = round($pricePerShare ?? ($this->register->valueAt($companyId, $at) ?? (float) $structure->initial_share_value), 2);
        if ($price <= 0) {
            throw ValidationException::withMessages(['price_per_share' => 'The issue price per share must be greater than zero']);
        }
        $this->register->assertWithinAuthorised($structure, $shares);
        if (! in_array($payment['pay_method'] ?? null, ['CASH', 'BANK'], true)) {
            throw ValidationException::withMessages(['pay_method' => 'Select the payment method']);
        }
        $receiving = $this->contributions->receivingAccount($companyId, (string) $payment['pay_method'], isset($payment['bank_account_id']) ? (int) $payment['bank_account_id'] : null);
        $amount = round($shares * $price, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['price_per_share' => 'The subscription amount must be greater than zero']);
        }

        $path = $document?->store("share-issuance-requests/{$companyId}", ShareIssuanceRequest::DISK);

        try {
            $request = ShareIssuanceRequest::create([
                'company_id' => $companyId,
                'share_holder_id' => $holder->id,
                'shares' => $shares,
                'price_per_share' => $price,
                'total_amount' => $amount,
                'issue_date' => $at->toDateString(),
                'pay_method' => $payment['pay_method'],
                'bank_account_id' => $receiving['bank'] ?? null,
                'receipt_number' => $payment['receipt_number'] ?? null,
                'cheque_number' => $payment['cheque_number'] ?? null,
                'notes' => $notes,
                'document_path' => $path,
                'document_name' => $document === null ? null : mb_substr(basename($document->getClientOriginalName()), 0, 191),
                'idempotency_key' => $idempotencyKey,
                'status' => ShareIssuanceRequest::STATUS_PENDING,
                'requested_by' => $requestedBy->id,
            ]);
        } catch (Throwable $exception) {
            if ($path !== null) {
                Storage::disk(ShareIssuanceRequest::DISK)->delete($path);
            }
            $previous = $exception instanceof UniqueConstraintViolationException ? $this->replayedRequest($idempotencyKey, $holder, $shares) : null;
            if ($previous === null) {
                throw $exception;
            }

            return ['request' => $previous, 'created' => false];
        }

        return ['request' => $request, 'created' => true];
    }

    /**
     * Approve a pending paid share issuance: a different authorised user than the requester (and than the shareholder's own
     * login account) posts it — the capital contribution with its journal Dr COMPANY ACCOUNT / bank, Cr CAPITAL ACCOUNT and the
     * share transaction, dated the approval date at the requested price — in one transaction with the request row locked.
     *
     * @throws ValidationException
     */
    public function approveIssuance(ShareIssuanceRequest $request, Employee $approver): ShareIssuanceRequest
    {
        return DB::transaction(function () use ($request, $approver): ShareIssuanceRequest {
            $locked = ShareIssuanceRequest::whereKey($request->id)->with('shareHolder')->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => 'This share issuance is not pending approval.']);
            }
            $this->duties->assertCanApprove($this->requestInitiatorIds($locked), $approver, 'share issuance', workflow: ApprovalPolicy::SHARE_ISSUANCES);

            $transaction = $this->issue(
                $locked->shareHolder,
                (int) $locked->shares,
                ShareTransactionType::Issuance->value,
                ShareTransaction::TREATMENT_PAID,
                (float) $locked->price_per_share,
                null,
                [
                    'pay_method' => $locked->pay_method,
                    'bank_account_id' => $locked->bank_account_id,
                    'receipt_number' => $locked->receipt_number,
                    'cheque_number' => $locked->cheque_number,
                ],
                $locked->notes,
                $approver,
                "share-issuance-request-{$locked->id}",
            )['transaction'];

            if ($locked->document_path !== null && $transaction->capital_id !== null) {
                Capital::whereKey($transaction->capital_id)->update(['receipt_file' => $locked->document_path, 'receipt_file_name' => $locked->document_name]);
            }

            $locked->update([
                'status' => ShareIssuanceRequest::STATUS_APPROVED,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'share_transaction_id' => $transaction->id,
                'capital_id' => $transaction->capital_id,
            ]);

            return $locked;
        });
    }

    /**
     * Reject a pending paid share issuance: nothing was posted; the request is kept with the reason.
     *
     * @throws ValidationException
     */
    public function rejectIssuance(ShareIssuanceRequest $request, string $reason, Employee $employee): ShareIssuanceRequest
    {
        return DB::transaction(function () use ($request, $reason, $employee): ShareIssuanceRequest {
            $locked = ShareIssuanceRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['reason' => 'Only pending share issuances can be rejected.']);
            }

            $locked->update(['status' => ShareIssuanceRequest::STATUS_REJECTED, 'rejected_by' => $employee->id, 'rejected_at' => now(), 'rejection_reason' => $reason]);

            return $locked;
        });
    }

    /**
     * Employees who may not approve the request (rule 6): the requester and the shareholder's own login account.
     *
     * @return list<int|null>
     */
    public function requestInitiatorIds(ShareIssuanceRequest $request): array
    {
        return [$request->requested_by, $request->shareHolder?->employee_id];
    }

    /**
     * Pending paid share issuance requests of a company, oldest first.
     *
     * @return Collection<int, ShareIssuanceRequest>
     */
    public function pendingIssuanceRequests(int $companyId): Collection
    {
        return ShareIssuanceRequest::where('company_id', $companyId)
            ->where('status', ShareIssuanceRequest::STATUS_PENDING)
            ->with(['shareHolder', 'requester'])
            ->orderBy('id')
            ->get()
            ->toBase();
    }

    private function replayedRequest(?string $idempotencyKey, ShareHolder $holder, int $shares): ?ShareIssuanceRequest
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $previous = ShareIssuanceRequest::where('idempotency_key', $idempotencyKey)->first();
        if ($previous !== null && ((int) $previous->company_id !== (int) $holder->company_id || (int) $previous->share_holder_id !== $holder->id || (int) $previous->shares !== $shares)) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different share issuance']);
        }

        return $previous;
    }

    /**
     * Create the share structure, its initial valuation and the initial allocation in one database transaction.
     * The allocated shares must equal the initial number of shares.
     *
     * @param  list<array{share_holder_id: int, shares: int, treatment: string, capital_id?: int|null, pay_method?: string|null, bank_account_id?: int|null, receipt_number?: string|null, cheque_number?: string|null}>  $allocations
     * @return array{structure: ShareStructure, created: bool}
     */
    public function establish(
        int $companyId,
        float $capitalBasis,
        int $totalShares,
        ?int $authorisedShares,
        CarbonInterface $establishedOn,
        ?string $notes,
        array $allocations,
        Employee $performedBy,
        ?string $idempotencyKey = null,
    ): array {
        $existing = $this->register->structure($companyId);
        if ($existing !== null) {
            if ($idempotencyKey !== null && $existing->idempotency_key === $idempotencyKey) {
                return ['structure' => $existing, 'created' => false];
            }

            throw ValidationException::withMessages(['total_shares' => 'The share structure has already been set up']);
        }

        $capitalBasis = round($capitalBasis, 2);
        $establishedOn = CarbonImmutable::parse($establishedOn->toDateString());

        if ($capitalBasis <= 0 || $totalShares <= 0) {
            throw ValidationException::withMessages(['total_shares' => 'The capital basis and the number of shares must be greater than zero']);
        }
        if ($authorisedShares !== null && $authorisedShares < $totalShares) {
            throw ValidationException::withMessages(['authorised_shares' => 'The authorised share limit cannot be lower than the initial number of shares']);
        }
        if ($establishedOn->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['established_on' => 'The date cannot be in the future']);
        }
        if (array_sum(array_map(fn (array $allocation): int => (int) $allocation['shares'], $allocations)) !== $totalShares) {
            throw ValidationException::withMessages(['allocations' => 'The initial allocation must allocate exactly '.number_format($totalShares).' shares']);
        }

        $shareValue = round($capitalBasis / $totalShares, 2);
        if ($shareValue <= 0) {
            throw ValidationException::withMessages(['capital_basis' => 'The share value (capital basis ÷ shares) must be greater than zero']);
        }

        try {
            $structure = DB::transaction(function () use ($companyId, $capitalBasis, $totalShares, $authorisedShares, $establishedOn, $notes, $allocations, $performedBy, $idempotencyKey, $shareValue): ShareStructure {
                $structure = ShareStructure::create([
                    'company_id' => $companyId,
                    'authorised_shares' => $authorisedShares,
                    'initial_capital_basis' => $capitalBasis,
                    'initial_shares' => $totalShares,
                    'initial_share_value' => $shareValue,
                    'established_on' => $establishedOn->toDateString(),
                    'notes' => $notes,
                    'created_by' => $performedBy->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
                $structure = $this->register->lockStructure($companyId);

                $this->valuations->recordInitial($structure, $performedBy);
                $at = $this->register->moment($establishedOn, null, 'established_on');

                foreach (array_values($allocations) as $index => $allocation) {
                    $holder = ShareHolder::where('company_id', $companyId)->find($allocation['share_holder_id'])
                        ?? throw ValidationException::withMessages(["allocations.{$index}.share_holder_id" => 'Select a registered shareholder']);
                    $shares = (int) $allocation['shares'];
                    $amount = round($shares * $shareValue, 2);

                    $link = $this->settle(
                        $holder,
                        $allocation['treatment'],
                        $amount,
                        $allocation,
                        $at,
                        $performedBy,
                        $idempotencyKey === null ? null : "shares-{$idempotencyKey}-{$index}",
                        'SHARE CAPITAL - INITIAL ALLOCATION - '.$holder->full_name,
                        null,
                    );

                    $this->register->record($structure, ShareTransactionType::InitialAllocation, null, $holder, $shares, $at, $performedBy, [
                        'share_value' => $shareValue,
                        'price_per_share' => $shareValue,
                        'total_amount' => $link['amount'],
                        'payment_treatment' => $allocation['treatment'],
                        'capital_id' => $link['capital_id'],
                        'journal_entry_id' => $link['journal_entry_id'],
                        'notes' => $notes,
                    ]);
                }

                return $structure;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = $this->register->structure($companyId);
            if ($existing === null || $idempotencyKey === null || $existing->idempotency_key !== $idempotencyKey) {
                throw ValidationException::withMessages(['total_shares' => 'The share structure has already been set up']);
            }

            return ['structure' => $existing, 'created' => false];
        }

        return ['structure' => $structure, 'created' => true];
    }

    /**
     * Issue new shares to a shareholder / investor.
     *
     * @param  string  $type  'issuance' (paid or linked to a recorded contribution) or 'bonus_issuance' (non-cash)
     * @param  array{pay_method?: string|null, bank_account_id?: int|null, receipt_number?: string|null, cheque_number?: string|null, capital_id?: int|null}  $payment
     * @return array{transaction: ShareTransaction, created: bool}
     */
    public function issue(
        ShareHolder $holder,
        int $shares,
        string $type,
        string $treatment,
        ?float $pricePerShare,
        ?CarbonInterface $date,
        array $payment,
        ?string $notes,
        Employee $performedBy,
        ?string $idempotencyKey = null,
        ?UploadedFile $document = null,
    ): array {
        $companyId = (int) $holder->company_id;
        $transactionType = $type === ShareTransactionType::BonusIssuance->value ? ShareTransactionType::BonusIssuance : ShareTransactionType::Issuance;

        if ($transactionType === ShareTransactionType::BonusIssuance) {
            $treatment = ShareTransaction::TREATMENT_NO_CASH;
        } elseif (! in_array($treatment, [ShareTransaction::TREATMENT_PAID, ShareTransaction::TREATMENT_LINKED], true)) {
            throw ValidationException::withMessages(['payment_treatment' => 'A share issuance must be paid now or linked to a recorded capital contribution; use a bonus issuance for shares issued without cash']);
        }

        return $this->register->idempotent($idempotencyKey, $companyId, [$transactionType], null, $holder->id, $shares, function () use ($holder, $shares, $transactionType, $treatment, $pricePerShare, $date, $payment, $notes, $performedBy, $idempotencyKey, $document, $companyId): ShareTransaction {
            $structure = $this->register->requireStructure($companyId);
            $at = $this->register->moment($date, $structure, 'issue_date');
            $currentValue = $this->register->valueAt($companyId, $at) ?? (float) $structure->initial_share_value;
            $price = $transactionType === ShareTransactionType::BonusIssuance ? null : round($pricePerShare ?? $currentValue, 2);

            if ($price !== null && $price <= 0) {
                throw ValidationException::withMessages(['price_per_share' => 'The issue price per share must be greater than zero']);
            }
            $this->register->assertWithinAuthorised($structure, $shares);

            $paid = $treatment === ShareTransaction::TREATMENT_PAID;
            $receiptPath = null;

            try {
                return $this->register->withDocument($paid ? null : $document, $companyId, function (array $documentColumns) use ($holder, $shares, $transactionType, $treatment, $price, $at, $payment, $notes, $performedBy, $idempotencyKey, $document, $paid, $companyId, &$receiptPath): ShareTransaction {
                    $structure = $this->register->lockStructure($companyId);
                    $amount = $price === null ? null : round($shares * $price, 2);

                    $link = $this->settle(
                        $holder,
                        $treatment,
                        (float) $amount,
                        $payment,
                        $at,
                        $performedBy,
                        $idempotencyKey === null ? null : "shares-{$idempotencyKey}",
                        'SHARE CAPITAL - SHARE ISSUANCE - '.$holder->full_name,
                        $paid ? $document : null,
                        $receiptPath,
                    );

                    return $this->register->record($structure, $transactionType, null, $holder, $shares, $at, $performedBy, $documentColumns + [
                        'price_per_share' => $price,
                        'total_amount' => $link['amount'],
                        'payment_treatment' => $treatment,
                        'capital_id' => $link['capital_id'],
                        'journal_entry_id' => $link['journal_entry_id'],
                        'notes' => $notes,
                        'idempotency_key' => $idempotencyKey,
                    ]);
                });
            } catch (Throwable $exception) {
                if ($receiptPath !== null) {
                    Storage::disk(Capital::DISK)->delete($receiptPath);
                }

                throw $exception;
            }
        });
    }

    /**
     * Apply the payment treatment of an issuing movement.
     *
     * @param  array{pay_method?: string|null, bank_account_id?: int|null, receipt_number?: string|null, cheque_number?: string|null, capital_id?: int|null}  $payment
     * @return array{amount: float|null, capital_id: int|null, journal_entry_id: int|null}
     */
    private function settle(
        ShareHolder $holder,
        string $treatment,
        float $amount,
        array $payment,
        CarbonImmutable $at,
        Employee $performedBy,
        ?string $contributionKey,
        string $description,
        ?UploadedFile $receipt,
        ?string &$receiptPath = null,
    ): array {
        return match ($treatment) {
            ShareTransaction::TREATMENT_PAID => $this->recordPayment($holder, $amount, $payment, $at, $performedBy, $contributionKey, $description, $receipt, $receiptPath),
            ShareTransaction::TREATMENT_LINKED => $this->linkContribution($holder, isset($payment['capital_id']) ? (int) $payment['capital_id'] : null),
            ShareTransaction::TREATMENT_NO_CASH => ['amount' => null, 'capital_id' => null, 'journal_entry_id' => null],
            default => throw ValidationException::withMessages(['payment_treatment' => 'Select how the shares are paid for']),
        };
    }

    /**
     * Paid now: Dr COMPANY ACCOUNT / bank, Cr CAPITAL ACCOUNT through the capital contribution service.
     *
     * @param  array{pay_method?: string|null, bank_account_id?: int|null, receipt_number?: string|null, cheque_number?: string|null}  $payment
     * @return array{amount: float, capital_id: int, journal_entry_id: int|null}
     */
    private function recordPayment(ShareHolder $holder, float $amount, array $payment, CarbonImmutable $at, Employee $performedBy, ?string $contributionKey, string $description, ?UploadedFile $receipt, ?string &$receiptPath): array
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['price_per_share' => 'The subscription amount must be greater than zero']);
        }
        if (! in_array($payment['pay_method'] ?? null, ['CASH', 'BANK'], true)) {
            throw ValidationException::withMessages(['pay_method' => 'Select the payment method']);
        }

        $result = $this->contributions->contribute(
            $holder,
            $amount,
            (string) $payment['pay_method'],
            isset($payment['bank_account_id']) ? (int) $payment['bank_account_id'] : null,
            $performedBy,
            $payment['receipt_number'] ?? null,
            $payment['cheque_number'] ?? null,
            $at,
            $contributionKey,
            function (Capital $capital) use ($receipt, &$receiptPath): void {
                if ($receipt !== null) {
                    $capital->attachReceipt($receipt);
                    $receiptPath = $capital->receipt_file;
                }
            },
            $description,
        );

        if (! $result['created'] && ShareTransaction::where('capital_id', $result['capital']->id)->where('status', ShareTransaction::COMPLETED)->exists()) {
            throw ValidationException::withMessages(['idempotency_key' => 'This payment is already linked to a share transaction']);
        }

        return ['amount' => (float) $result['capital']->amount, 'capital_id' => $result['capital']->id, 'journal_entry_id' => $result['capital']->journal_entry_id];
    }

    /**
     * Link an already recorded contribution of the same shareholder that no other active share transaction uses.
     *
     * @return array{amount: float, capital_id: int, journal_entry_id: int|null}
     */
    private function linkContribution(ShareHolder $holder, ?int $capitalId): array
    {
        $capital = $capitalId === null ? null : Capital::where('company_id', $holder->company_id)->whereKey($capitalId)->lockForUpdate()->first();

        if ($capital === null) {
            throw ValidationException::withMessages(['capital_id' => 'Select the recorded capital contribution that paid for these shares']);
        }
        if ($capital->isReversed()) {
            throw ValidationException::withMessages(['capital_id' => 'The selected capital contribution has been reversed']);
        }
        if (! $capital->isPosted()) {
            throw ValidationException::withMessages(['capital_id' => 'The selected capital contribution has not been approved']);
        }
        if ((int) $capital->share_holder_id !== $holder->id) {
            throw ValidationException::withMessages(['capital_id' => 'The selected capital contribution belongs to another shareholder']);
        }

        $linked = ShareTransaction::where('capital_id', $capital->id)->where('status', ShareTransaction::COMPLETED)->value('reference');
        if ($linked !== null) {
            throw ValidationException::withMessages(['capital_id' => "The selected capital contribution is already linked to share transaction {$linked}"]);
        }

        return ['amount' => (float) $capital->amount, 'capital_id' => $capital->id, 'journal_entry_id' => $capital->journal_entry_id];
    }
}
