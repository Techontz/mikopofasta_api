<?php

namespace App\Services\Shares;

use App\Enums\ShareTransactionType;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Models\SharePosition;
use App\Models\ShareStructure;
use App\Models\ShareTransaction;
use App\Models\ShareValuation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The share register — the single source of shareholder OWNERSHIP.
 *
 *  - SHARES are ownership units; each movement is an immutable `share_transactions` row.
 *  - OWNERSHIP % = shareholder shares ÷ total issued shares × 100 (computed on read, never stored or typed in).
 *  - SHARE VALUE = the value per share effective on a date (latest effective valuation dated on or before it).
 *  - HOLDING VALUE = shares × share value; COMPANY SHARE VALUATION = total issued shares × share value.
 *
 * Current positions (`share_positions`) are written only here, inside the database transaction that records the
 * movement, with the affected position rows locked FOR UPDATE; {@see verify()} proves they equal a replay of the
 * transactions. Total issued shares change only through issuing / cancelling rows, which also lock the company's
 * share structure row, so the authorised limit cannot be overrun by concurrent requests.
 *
 * Shares are never cash: nothing here posts to the ledger. Paid issuances post through the capital contribution
 * service ({@see ShareIssuance}).
 */
class ShareRegister
{
    public const PERCENT_PRECISION = 4;

    public function structure(int $companyId): ?ShareStructure
    {
        return ShareStructure::where('company_id', $companyId)->first();
    }

    /**
     * @throws ValidationException when the company has no share structure yet
     */
    public function requireStructure(int $companyId): ShareStructure
    {
        return $this->structure($companyId) ?? throw ValidationException::withMessages(['shares' => 'Set up the share structure first (Shares → Overview)']);
    }

    /**
     * Lock the company's share structure row (serialises every change to total issued shares and share value).
     */
    public function lockStructure(int $companyId): ShareStructure
    {
        return ShareStructure::where('company_id', $companyId)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['shares' => 'Set up the share structure first (Shares → Overview)']);
    }

    /**
     * Share value effective on a date (today when null); null before any valuation exists.
     */
    public function valueAt(int $companyId, ?CarbonInterface $date = null): ?float
    {
        $value = ShareValuation::where('company_id', $companyId)
            ->effective()
            ->when($date !== null, fn ($query) => $query->whereDate('valuation_date', '<=', $date->toDateString()))
            ->orderByDesc('valuation_date')
            ->orderByDesc('id')
            ->value('new_value');

        return $value === null ? null : round((float) $value, 2);
    }

    public function currentValue(int $companyId): float
    {
        return $this->valueAt($companyId) ?? 0.0;
    }

    /**
     * Shares held per shareholder id: current positions, or a replay of the transactions up to the end of $asOf.
     *
     * @return Collection<int, int>
     */
    public function holdings(int $companyId, ?CarbonInterface $asOf = null): Collection
    {
        if ($asOf !== null) {
            return $this->replay($companyId, $asOf);
        }

        return SharePosition::where('company_id', $companyId)->pluck('shares', 'share_holder_id')->map(fn ($shares): int => (int) $shares);
    }

    /**
     * Total issued shares (now, or at the end of $asOf).
     */
    public function issuedShares(int $companyId, ?CarbonInterface $asOf = null): int
    {
        return (int) $this->holdings($companyId, $asOf)->sum();
    }

    /**
     * Shares per shareholder recomputed from the immutable transaction history alone.
     *
     * @return Collection<int, int>
     */
    public function replay(int $companyId, ?CarbonInterface $asOf = null): Collection
    {
        $base = fn () => ShareTransaction::where('company_id', $companyId)
            ->when($asOf !== null, fn ($query) => $query->where('transacted_at', '<=', CarbonImmutable::parse($asOf)->endOfDay()));

        $received = $base()->whereNotNull('to_share_holder_id')->groupBy('to_share_holder_id')->selectRaw('to_share_holder_id AS holder, SUM(shares) AS total')->pluck('total', 'holder');
        $givenUp = $base()->whereNotNull('from_share_holder_id')->groupBy('from_share_holder_id')->selectRaw('from_share_holder_id AS holder, SUM(shares) AS total')->pluck('total', 'holder');

        return $received->keys()->merge($givenUp->keys())->unique()->sort()->values()
            ->mapWithKeys(fn ($holder): array => [(int) $holder => (int) ($received[$holder] ?? 0) - (int) ($givenUp[$holder] ?? 0)]);
    }

    /**
     * Positions compared with the transaction replay.
     *
     * @return array{consistent: bool, mismatches: list<array{share_holder_id: int, position: int, replay: int}>}
     */
    public function verify(int $companyId): array
    {
        $positions = $this->holdings($companyId);
        $replay = $this->replay($companyId);
        $mismatches = [];

        foreach ($positions->keys()->merge($replay->keys())->unique() as $holderId) {
            if ((int) ($positions[$holderId] ?? 0) !== (int) ($replay[$holderId] ?? 0)) {
                $mismatches[] = ['share_holder_id' => (int) $holderId, 'position' => (int) ($positions[$holderId] ?? 0), 'replay' => (int) ($replay[$holderId] ?? 0)];
            }
        }

        return ['consistent' => $mismatches === [], 'mismatches' => $mismatches];
    }

    public function percentOf(int $shares, int $totalShares): float
    {
        return $totalShares > 0 ? round($shares / $totalShares * 100, self::PERCENT_PRECISION) : 0.0;
    }

    public function holdingValue(int $shares, float $shareValue): float
    {
        return round($shares * $shareValue, 2);
    }

    /**
     * Every shareholder of the company with shares, ownership %, share value and holding value — now or as of a date.
     *
     * @return Collection<int, array{share_holder: ShareHolder, shares: int, total_shares: int, ownership_percent: float, share_value: float, holding_value: float, date_acquired: ?string, status: string}>
     */
    public function register(int $companyId, ?CarbonInterface $asOf = null): Collection
    {
        $holdings = $this->holdings($companyId, $asOf);
        $total = (int) $holdings->sum();
        $value = $this->valueAt($companyId, $asOf) ?? 0.0;
        $acquired = $asOf === null
            ? SharePosition::where('company_id', $companyId)->pluck('first_acquired_at', 'share_holder_id')
            : ShareTransaction::where('company_id', $companyId)->whereNotNull('to_share_holder_id')
                ->where('transacted_at', '<=', CarbonImmutable::parse($asOf)->endOfDay())
                ->groupBy('to_share_holder_id')->selectRaw('to_share_holder_id AS holder, MIN(transacted_at) AS acquired')->pluck('acquired', 'holder');

        return ShareHolder::where('company_id', $companyId)->orderBy('id')->get()
            ->map(function (ShareHolder $holder) use ($holdings, $total, $value, $acquired): array {
                $shares = (int) ($holdings[$holder->id] ?? 0);

                return [
                    'share_holder' => $holder,
                    'shares' => $shares,
                    'total_shares' => $total,
                    'ownership_percent' => $this->percentOf($shares, $total),
                    'share_value' => $value,
                    'holding_value' => $this->holdingValue($shares, $value),
                    'date_acquired' => $shares > 0 && isset($acquired[$holder->id]) ? CarbonImmutable::parse($acquired[$holder->id])->toDateString() : null,
                    'status' => $shares > 0 ? 'active' : 'no_shares',
                ];
            })
            ->values();
    }

    /**
     * One shareholder's holding as of a date: shares held then × share value effective then.
     *
     * @return array{date: string, shares: int, total_shares: int, ownership_percent: float, share_value: float, holding_value: float}
     */
    public function holdingAt(ShareHolder $holder, CarbonInterface $date): array
    {
        $holdings = $this->replay((int) $holder->company_id, $date);
        $shares = (int) ($holdings[$holder->id] ?? 0);
        $total = (int) $holdings->sum();
        $value = $this->valueAt((int) $holder->company_id, $date) ?? 0.0;

        return [
            'date' => $date->toDateString(),
            'shares' => $shares,
            'total_shares' => $total,
            'ownership_percent' => $this->percentOf($shares, $total),
            'share_value' => $value,
            'holding_value' => $this->holdingValue($shares, $value),
        ];
    }

    /**
     * Holding value history of a shareholder: one point per date on which their shares, total issued shares or the
     * share value changed, from their first acquisition until today.
     *
     * @return list<array{date: string, shares: int, total_shares: int, ownership_percent: float, share_value: float, holding_value: float}>
     */
    public function holdingHistory(ShareHolder $holder): array
    {
        $companyId = (int) $holder->company_id;
        $transactions = ShareTransaction::where('company_id', $companyId)->orderBy('transacted_at')->orderBy('id')
            ->get(['from_share_holder_id', 'to_share_holder_id', 'shares', 'transacted_at']);
        $valuations = ShareValuation::where('company_id', $companyId)->effective()->orderBy('valuation_date')->orderBy('id')->get(['valuation_date', 'new_value']);

        $dates = $transactions->map(fn (ShareTransaction $row): string => $row->transacted_at->toDateString())
            ->merge($valuations->map(fn (ShareValuation $row): string => $row->valuation_date->toDateString()))
            ->push(CarbonImmutable::today()->toDateString())
            ->unique()->sort()->values();

        $points = [];
        $shares = 0;
        $total = 0;
        $value = 0.0;
        $started = false;
        [$t, $v] = [0, 0];

        foreach ($dates as $date) {
            while ($t < $transactions->count() && $transactions[$t]->transacted_at->toDateString() <= $date) {
                $row = $transactions[$t++];
                $total += ($row->to_share_holder_id !== null ? $row->shares : 0) - ($row->from_share_holder_id !== null ? $row->shares : 0);
                if ((int) $row->to_share_holder_id === $holder->id) {
                    $shares += $row->shares;
                    $started = true;
                }
                if ((int) $row->from_share_holder_id === $holder->id) {
                    $shares -= $row->shares;
                }
            }
            while ($v < $valuations->count() && $valuations[$v]->valuation_date->toDateString() <= $date) {
                $value = round((float) $valuations[$v++]->new_value, 2);
            }

            if ($started) {
                $points[] = [
                    'date' => $date,
                    'shares' => $shares,
                    'total_shares' => $total,
                    'ownership_percent' => $this->percentOf($shares, $total),
                    'share_value' => $value,
                    'holding_value' => $this->holdingValue($shares, $value),
                ];
            }
        }

        return $points;
    }

    /**
     * The moment a movement is recorded at: now for today (or no date), the end of the day for a past date.
     *
     * @throws ValidationException for a future date or a date before the share structure was established
     */
    public function moment(?CarbonInterface $date, ?ShareStructure $structure, string $field = 'transaction_date'): CarbonImmutable
    {
        if ($date === null) {
            return CarbonImmutable::now();
        }

        $day = CarbonImmutable::parse($date->toDateString());
        if ($day->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages([$field => 'The date cannot be in the future']);
        }
        if ($structure !== null && $day->lt($structure->established_on)) {
            throw ValidationException::withMessages([$field => 'The date cannot be before the share structure was established ('.$structure->established_on->toDateString().')']);
        }

        return $day->isToday() ? CarbonImmutable::now() : $day->setTime(23, 59, 59);
    }

    /**
     * Movements that take shares away from a holder may not be back-dated before the latest recorded movement, so a
     * historical holding can never become negative.
     */
    public function assertNotBeforeLatest(int $companyId, CarbonImmutable $at, string $field = 'transaction_date'): void
    {
        $latest = ShareTransaction::where('company_id', $companyId)->max('transacted_at');

        if ($latest !== null && $at->lt(CarbonImmutable::parse($latest))) {
            throw ValidationException::withMessages([$field => 'This movement cannot be dated before the latest share transaction ('.CarbonImmutable::parse($latest)->toDateString().')']);
        }
    }

    /**
     * Shares still available under the authorised limit (null when the company has no limit).
     */
    public function availableShares(ShareStructure $structure): ?int
    {
        return $structure->authorised_shares === null ? null : max(0, $structure->authorised_shares - $this->issuedShares((int) $structure->company_id));
    }

    public function assertWithinAuthorised(ShareStructure $structure, int $additional): void
    {
        $available = $this->availableShares($structure);

        if ($available !== null && $additional > $available) {
            throw ValidationException::withMessages(['shares' => 'Only '.number_format($available).' shares are available to issue (authorised limit '.number_format((int) $structure->authorised_shares).')']);
        }
    }

    /**
     * Record one share movement and update the positions it touches. Must run inside a database transaction; callers
     * that change total issued shares must hold the structure lock ({@see lockStructure()}).
     *
     * @param  array<string, mixed>  $attributes  extra share_transactions columns (price, amounts, links, notes, key…)
     */
    public function record(
        ShareStructure $structure,
        ShareTransactionType $type,
        ?ShareHolder $from,
        ?ShareHolder $to,
        int $shares,
        CarbonImmutable $at,
        Employee $performedBy,
        array $attributes = [],
    ): ShareTransaction {
        $companyId = (int) $structure->company_id;

        if ($shares <= 0) {
            throw ValidationException::withMessages(['shares' => 'The number of shares must be greater than zero']);
        }
        if ($from === null && $to === null) {
            throw ValidationException::withMessages(['shares' => 'A share movement needs a shareholder']);
        }
        if ($from !== null && $to !== null && $from->id === $to->id) {
            throw ValidationException::withMessages(['to_share_holder_id' => 'The receiving shareholder must be different from the transferring shareholder']);
        }
        foreach ([$from, $to] as $holder) {
            if ($holder !== null && (int) $holder->company_id !== $companyId) {
                throw ValidationException::withMessages(['share_holder_id' => 'The shareholder does not belong to this company']);
            }
        }

        $positions = $this->lockPositions($companyId, array_filter([$from?->id, $to?->id]));

        if ($from !== null && $positions[$from->id]->shares < $shares) {
            throw ValidationException::withMessages(['shares' => $from->full_name.' holds only '.number_format($positions[$from->id]->shares).' shares']);
        }
        if ($from === null) {
            $this->assertWithinAuthorised($structure, $shares);
        }

        $transaction = ShareTransaction::create($attributes + [
            'company_id' => $companyId,
            'reference' => $this->newReference($type),
            'type' => $type,
            'from_share_holder_id' => $from?->id,
            'to_share_holder_id' => $to?->id,
            'shares' => $shares,
            'share_value' => $this->valueAt($companyId, $at) ?? (float) $structure->initial_share_value,
            'transacted_at' => $at,
            'status' => ShareTransaction::COMPLETED,
            'performed_by' => $performedBy->id,
        ]);

        if ($from !== null) {
            $position = $positions[$from->id];
            $position->shares -= $shares;
            if ($position->shares === 0) {
                $position->first_acquired_at = null;
            }
            $position->save();
        }
        if ($to !== null) {
            $position = $positions[$to->id];
            if ($position->shares === 0) {
                $position->first_acquired_at = $at;
            }
            $position->shares += $shares;
            $position->save();
        }

        return $transaction;
    }

    /**
     * Cancel shares held by a shareholder (returned to unissued; total issued shares decrease). No ledger entry.
     *
     * @return array{transaction: ShareTransaction, created: bool}
     */
    public function cancel(ShareHolder $holder, int $shares, ?CarbonInterface $date, string $reason, Employee $performedBy, ?string $idempotencyKey = null, ?UploadedFile $document = null): array
    {
        return $this->idempotent($idempotencyKey, (int) $holder->company_id, [ShareTransactionType::Cancellation], $holder->id, null, $shares, function () use ($holder, $shares, $date, $reason, $performedBy, $idempotencyKey, $document): ShareTransaction {
            $structure = $this->requireStructure((int) $holder->company_id);
            $at = $this->moment($date, $structure);
            $this->assertNotBeforeLatest((int) $holder->company_id, $at);

            return $this->withDocument($document, (int) $holder->company_id, function (array $documentColumns) use ($holder, $shares, $at, $reason, $performedBy, $idempotencyKey): ShareTransaction {
                $structure = $this->lockStructure((int) $holder->company_id);

                return $this->record($structure, ShareTransactionType::Cancellation, $holder, null, $shares, $at, $performedBy, $documentColumns + [
                    'notes' => $reason,
                    'idempotency_key' => $idempotencyKey,
                ]);
            });
        });
    }

    /**
     * Correct a holding by adding (from unissued) or removing (to unissued) shares, with a reason. No ledger entry.
     *
     * @return array{transaction: ShareTransaction, created: bool}
     */
    public function adjust(ShareHolder $holder, string $direction, int $shares, string $reason, Employee $performedBy, ?string $idempotencyKey = null): array
    {
        $increase = $direction === 'increase';

        return $this->idempotent($idempotencyKey, (int) $holder->company_id, [ShareTransactionType::Adjustment], $increase ? null : $holder->id, $increase ? $holder->id : null, $shares, function () use ($holder, $increase, $shares, $reason, $performedBy, $idempotencyKey): ShareTransaction {
            $this->requireStructure((int) $holder->company_id);
            $at = CarbonImmutable::now();

            return DB::transaction(function () use ($holder, $increase, $shares, $reason, $performedBy, $idempotencyKey, $at): ShareTransaction {
                $structure = $this->lockStructure((int) $holder->company_id);

                return $this->record($structure, ShareTransactionType::Adjustment, $increase ? null : $holder, $increase ? $holder : null, $shares, $at, $performedBy, [
                    'payment_treatment' => ShareTransaction::TREATMENT_NO_CASH,
                    'notes' => $reason,
                    'idempotency_key' => $idempotencyKey,
                ]);
            });
        });
    }

    /**
     * Reverse a share transaction with an opposite movement dated now. The original row stays as recorded and is
     * marked reversed. A linked capital contribution and its journal entry are financial records and are not touched.
     *
     * @return array{transaction: ShareTransaction, created: bool}
     */
    public function reverse(ShareTransaction $original, string $reason, Employee $performedBy): array
    {
        if ($original->type === ShareTransactionType::Reversal) {
            throw ValidationException::withMessages(['reason' => 'A reversal cannot itself be reversed']);
        }

        $existing = ShareTransaction::where('reversal_of_id', $original->id)->first();
        if ($existing !== null) {
            throw ValidationException::withMessages(['reason' => 'This share transaction has already been reversed ('.$existing->reference.')']);
        }

        try {
            $reversal = DB::transaction(function () use ($original, $reason, $performedBy): ShareTransaction {
                $structure = $this->lockStructure((int) $original->company_id);
                $locked = ShareTransaction::whereKey($original->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== ShareTransaction::COMPLETED) {
                    throw ValidationException::withMessages(['reason' => 'This share transaction has already been reversed']);
                }

                $reversal = $this->record(
                    $structure,
                    ShareTransactionType::Reversal,
                    $locked->toShareHolder,
                    $locked->fromShareHolder,
                    $locked->shares,
                    CarbonImmutable::now(),
                    $performedBy,
                    ['reversal_of_id' => $locked->id, 'notes' => $reason],
                );

                $locked->update(['status' => ShareTransaction::REVERSED]);

                return $reversal;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['reason' => 'This share transaction has already been reversed']);
        }

        return ['transaction' => $reversal, 'created' => true];
    }

    /**
     * Run a write once per idempotency key: a repeated key returns the transaction it recorded (when it describes the
     * same movement) instead of recording again.
     *
     * @param  list<ShareTransactionType>  $types
     * @param  Closure(): ShareTransaction  $write
     * @return array{transaction: ShareTransaction, created: bool}
     */
    public function idempotent(?string $idempotencyKey, int $companyId, array $types, ?int $fromId, ?int $toId, int $shares, Closure $write): array
    {
        $previous = $this->replayed($idempotencyKey, $companyId, $types, $fromId, $toId, $shares);
        if ($previous !== null) {
            return ['transaction' => $previous, 'created' => false];
        }

        try {
            return ['transaction' => $write(), 'created' => true];
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $this->replayed($idempotencyKey, $companyId, $types, $fromId, $toId, $shares);
            if ($previous === null) {
                throw $exception;
            }

            return ['transaction' => $previous, 'created' => false];
        }
    }

    /**
     * Store an optional supporting document on the private disk and run $write in a database transaction with the
     * document columns; the file is removed again when the write fails.
     *
     * @param  Closure(array{document_path?: string, document_name?: string}): ShareTransaction  $write
     */
    public function withDocument(?UploadedFile $document, int $companyId, Closure $write): ShareTransaction
    {
        $columns = $document === null ? [] : [
            'document_path' => $document->store("share-documents/{$companyId}", ShareTransaction::DISK),
            'document_name' => mb_substr(basename($document->getClientOriginalName()), 0, 191),
        ];

        try {
            return DB::transaction(fn (): ShareTransaction => $write($columns));
        } catch (Throwable $exception) {
            if (isset($columns['document_path'])) {
                Storage::disk(ShareTransaction::DISK)->delete($columns['document_path']);
            }

            throw $exception;
        }
    }

    public function newReference(ShareTransactionType $type): string
    {
        return $type->prefix().now()->format('ymd').strtoupper(Str::random(6));
    }

    /**
     * @param  list<ShareTransactionType>  $types
     */
    private function replayed(?string $idempotencyKey, int $companyId, array $types, ?int $fromId, ?int $toId, int $shares): ?ShareTransaction
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $previous = ShareTransaction::where('idempotency_key', $idempotencyKey)->first();
        if ($previous === null) {
            return null;
        }

        $same = (int) $previous->company_id === $companyId
            && in_array($previous->type, $types, true)
            && ($previous->from_share_holder_id === null ? null : (int) $previous->from_share_holder_id) === $fromId
            && ($previous->to_share_holder_id === null ? null : (int) $previous->to_share_holder_id) === $toId
            && $previous->shares === $shares;

        if (! $same) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different share transaction']);
        }

        return $previous;
    }

    /**
     * Position rows of the given shareholders (created at zero when missing), locked FOR UPDATE in id order.
     *
     * @param  array<int, int>  $holderIds
     * @return Collection<int, SharePosition>
     */
    private function lockPositions(int $companyId, array $holderIds): Collection
    {
        $ids = array_values(array_unique(array_map('intval', $holderIds)));
        sort($ids);

        SharePosition::insertOrIgnore(array_map(fn (int $id): array => [
            'company_id' => $companyId,
            'share_holder_id' => $id,
            'shares' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $ids));

        return SharePosition::where('company_id', $companyId)
            ->whereIn('share_holder_id', $ids)
            ->orderBy('share_holder_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('share_holder_id');
    }
}
