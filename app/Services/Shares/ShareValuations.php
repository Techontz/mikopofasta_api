<?php

namespace App\Services\Shares;

use App\Models\Employee;
use App\Models\ShareStructure;
use App\Models\ShareValuation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Share value changes. Each change is a new `share_valuations` row recording the previous and new value per share,
 * the effective date, the total issued shares at that date and the previous and new company share valuation.
 * History is never overwritten; a wrong latest valuation is marked reversed (its figures stay).
 *
 * A valuation changes holding values only — never share counts or ownership — and it is a memorandum record: it
 * posts no journal entry and is never cash or a bank balance.
 */
class ShareValuations
{
    public function __construct(private readonly ShareRegister $register) {}

    /**
     * The valuation created with a new share structure (initial capital basis ÷ initial shares).
     */
    public function recordInitial(ShareStructure $structure, Employee $performedBy): ShareValuation
    {
        $value = (float) $structure->initial_share_value;

        return ShareValuation::create([
            'company_id' => $structure->company_id,
            'reference' => $this->newReference(),
            'kind' => 'initial',
            'previous_value' => null,
            'new_value' => $value,
            'valuation_date' => $structure->established_on,
            'total_shares' => $structure->initial_shares,
            'previous_total_valuation' => null,
            'new_total_valuation' => round($structure->initial_shares * $value, 2),
            'reason' => 'Initial share structure',
            'status' => ShareValuation::EFFECTIVE,
            'performed_by' => $performedBy->id,
        ]);
    }

    /**
     * Record a new share value effective from $date (today when null).
     *
     * @return array{valuation: ShareValuation, created: bool}
     */
    public function revalue(int $companyId, float $newValue, ?CarbonInterface $date, string $reason, Employee $performedBy, ?string $idempotencyKey = null): array
    {
        $newValue = round($newValue, 2);
        $date = CarbonImmutable::parse(($date ?? CarbonImmutable::today())->toDateString());

        $previous = $this->replayed($idempotencyKey, $companyId, $newValue, $date);
        if ($previous !== null) {
            return ['valuation' => $previous, 'created' => false];
        }

        if ($newValue <= 0) {
            throw ValidationException::withMessages(['new_value' => 'The share value must be greater than zero']);
        }
        if ($date->gt(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['valuation_date' => 'The valuation date cannot be in the future']);
        }

        try {
            $valuation = DB::transaction(function () use ($companyId, $newValue, $date, $reason, $performedBy, $idempotencyKey): ShareValuation {
                $structure = $this->register->lockStructure($companyId);
                if ($date->lt($structure->established_on)) {
                    throw ValidationException::withMessages(['valuation_date' => 'The valuation date cannot be before the share structure was established ('.$structure->established_on->toDateString().')']);
                }

                $latest = $this->latest($companyId);
                if ($latest !== null && $date->lt($latest->valuation_date)) {
                    throw ValidationException::withMessages(['valuation_date' => 'The valuation date cannot be before the latest valuation ('.$latest->valuation_date->toDateString().')']);
                }

                $previousValue = $latest === null ? null : round((float) $latest->new_value, 2);
                if ($previousValue !== null && abs($previousValue - $newValue) < 0.005) {
                    throw ValidationException::withMessages(['new_value' => 'The new share value is the same as the current share value']);
                }

                $totalShares = $this->register->issuedShares($companyId, $date);

                return ShareValuation::create([
                    'company_id' => $companyId,
                    'reference' => $this->newReference(),
                    'kind' => 'revaluation',
                    'previous_value' => $previousValue,
                    'new_value' => $newValue,
                    'valuation_date' => $date->toDateString(),
                    'total_shares' => $totalShares,
                    'previous_total_valuation' => $previousValue === null ? null : round($totalShares * $previousValue, 2),
                    'new_total_valuation' => round($totalShares * $newValue, 2),
                    'reason' => $reason,
                    'status' => ShareValuation::EFFECTIVE,
                    'performed_by' => $performedBy->id,
                    'idempotency_key' => $idempotencyKey,
                ]);
            });
        } catch (UniqueConstraintViolationException $exception) {
            $previous = $this->replayed($idempotencyKey, $companyId, $newValue, $date);
            if ($previous === null) {
                throw $exception;
            }

            return ['valuation' => $previous, 'created' => false];
        }

        return ['valuation' => $valuation, 'created' => true];
    }

    /**
     * Mark the latest valuation reversed (entered in error): the value effective before it applies again.
     */
    public function reverse(ShareValuation $valuation, string $reason, Employee $performedBy): ShareValuation
    {
        return DB::transaction(function () use ($valuation, $reason, $performedBy): ShareValuation {
            $this->register->lockStructure((int) $valuation->company_id);
            $locked = ShareValuation::whereKey($valuation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ShareValuation::EFFECTIVE) {
                throw ValidationException::withMessages(['reason' => 'This valuation has already been reversed']);
            }
            if ($locked->kind === 'initial') {
                throw ValidationException::withMessages(['reason' => 'The initial valuation of the share structure cannot be reversed']);
            }
            if ($this->latest((int) $locked->company_id)?->id !== $locked->id) {
                throw ValidationException::withMessages(['reason' => 'Only the latest valuation can be reversed']);
            }

            $locked->update([
                'status' => ShareValuation::REVERSED,
                'reversed_at' => now(),
                'reversed_by' => $performedBy->id,
                'reversal_reason' => $reason,
            ]);

            return $locked;
        });
    }

    /**
     * Latest effective valuation (the current share value).
     */
    public function latest(int $companyId): ?ShareValuation
    {
        return ShareValuation::where('company_id', $companyId)->effective()->orderByDesc('valuation_date')->orderByDesc('id')->first();
    }

    private function replayed(?string $idempotencyKey, int $companyId, float $newValue, CarbonImmutable $date): ?ShareValuation
    {
        if ($idempotencyKey === null) {
            return null;
        }

        $previous = ShareValuation::where('idempotency_key', $idempotencyKey)->first();
        if ($previous === null) {
            return null;
        }

        if ((int) $previous->company_id !== $companyId || abs((float) $previous->new_value - $newValue) > 0.001 || $previous->valuation_date->toDateString() !== $date->toDateString()) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different valuation']);
        }

        return $previous;
    }

    private function newReference(): string
    {
        return 'SHV'.now()->format('ymd').strtoupper(Str::random(6));
    }
}
