<?php

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetDocument;
use App\Models\AssetEvent;
use App\Models\Branch;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use App\Services\CapitalContributions;
use App\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Asset capital contributions and the Asset Registry lifecycle.
 *
 *  - Contribution (C6 maker/checker): the request writes the `capitals` row (pending) + `assets` row (status pending) +
 *    history in one database transaction — no journal, not counted anywhere. A different authorised user approves it
 *    ({@see self::approve()}): journal Dr fixed-asset account by type / Cr CAPITAL ACCOUNT dated the approval date, asset
 *    active; or rejects it ({@see self::reject()}): nothing posted, asset status rejected. The Asset ID (AST-000001, per
 *    company) is assigned after insert under a company row lock; the QR token is a random UUID unrelated to the id.
 *  - The contribution value is the shareholder's contributed capital forever. Revaluations change only `current_value`
 *    and are memo-only history (the chart has no revaluation surplus / impairment accounts), so they never touch
 *    contributed capital or the ledger.
 *  - Transfers (branch), status changes (incl. disposal / write-off) and descriptive edits are history events; disposal and
 *    write-off accounting is not modelled — the asset stays at its contributed cost in the ledger until the contribution
 *    is reversed.
 *  - Reversal (correction): Ledger::reverse of the contribution journal, contribution and asset marked reversed; blocked
 *    while shares issued against the contribution are active.
 */
class AssetRegistry
{
    /** Asset status while its contribution awaits approval (not in config('assets.statuses'): never set manually). */
    public const PENDING = 'pending';

    /** Asset status of a rejected contribution (terminal). */
    public const REJECTED = 'rejected';

    public function __construct(
        private readonly AssetTypes $types,
        private readonly CapitalContributions $contributions,
        private readonly Ledger $ledger,
    ) {}

    /**
     * quantity × unit value with decimal-safe arithmetic ("2500000.00").
     */
    public static function total(int $quantity, string|int|float $unitValue): string
    {
        return bcmul(number_format((float) $unitValue, 2, '.', ''), (string) $quantity, 2);
    }

    /**
     * @param  array<string, mixed>  $data  validated asset contribution data
     * @return array{asset: Asset, created: bool}
     */
    public function contribute(ShareHolder $holder, array $data, Employee $recordedBy, ?string $idempotencyKey = null): array
    {
        $type = (string) $data['asset_type'];
        $config = $this->types->get($type);
        $quantity = $config['fixed_quantity'] ? 1 : (int) $data['quantity'];
        $unitValue = number_format((float) $data['unit_value'], 2, '.', '');
        $total = self::total($quantity, $unitValue);
        $contributedOn = CarbonImmutable::parse((string) $data['contribution_date'])->startOfDay();
        $contributedAt = $contributedOn->isToday() ? CarbonImmutable::now() : $contributedOn;
        $branch = Branch::where('company_id', $holder->company_id)->findOrFail((int) $data['branch_id']);
        $account = $this->types->account($type);
        $name = trim((string) $data['name']);

        $result = $this->contributions->contributeAsset(
            $holder,
            (float) $total,
            $account,
            $recordedBy,
            $contributedAt,
            $idempotencyKey,
            function (Capital $capital) use ($holder, $data, $type, $config, $quantity, $unitValue, $total, $contributedOn, $branch, $account, $name, $recordedBy): void {
                $asset = Asset::create([
                    'company_id' => $holder->company_id,
                    'capital_id' => $capital->id,
                    'share_holder_id' => $holder->id,
                    'qr_token' => (string) Str::uuid(),
                    'asset_type' => $type,
                    'name' => $name,
                    'description' => trim((string) $data['description']),
                    'quantity' => $quantity,
                    'unit_value' => $unitValue,
                    'contribution_value' => $total,
                    'current_value' => $total,
                    'condition' => $config['condition'] ? ($data['condition'] ?? null) : null,
                    'contributed_on' => $contributedOn->toDateString(),
                    'branch_id' => $branch->id,
                    'location' => $data['location'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'valuation_method' => $data['valuation_method'],
                    'valuation_date' => CarbonImmutable::parse((string) $data['valuation_date'])->toDateString(),
                    'valued_by' => $data['valued_by'] ?? null,
                    'valuation_reference' => $data['valuation_reference'] ?? null,
                    'valuation_notes' => $data['valuation_notes'] ?? null,
                    'specifications' => $this->types->specifications($type, (array) ($data['specifications'] ?? [])),
                    'ledger_account' => $account->value,
                    'journal_entry_id' => null,
                    'status' => self::PENDING,
                    'recorded_by' => $recordedBy->id,
                ]);

                $this->assignCode($asset);

                $this->event($asset, 'created', $recordedBy, new: ['asset_code' => $asset->asset_code, 'name' => $asset->name, 'asset_type' => $type, 'quantity' => $quantity, 'unit_value' => $unitValue, 'status' => self::PENDING], after: $total);
            },
        );

        $asset = $result['capital']->asset;
        if ($asset === null) {
            throw ValidationException::withMessages(['idempotency_key' => 'This request key was already used for a different contribution']);
        }

        return ['asset' => $asset, 'created' => $result['created']];
    }

    /**
     * Approve a pending asset contribution (a different authorised user than the one who recorded it, rule 6): posts Dr the
     * fixed-asset account / Cr CAPITAL ACCOUNT dated today, activates the asset and records the history, in one transaction.
     *
     * @throws ValidationException
     */
    public function approve(Asset $asset, Employee $approver): Asset
    {
        return DB::transaction(function () use ($asset, $approver): Asset {
            $locked = Asset::whereKey($asset->id)->with(['branch', 'shareHolder'])->lockForUpdate()->firstOrFail();
            if ($locked->status !== self::PENDING) {
                throw ValidationException::withMessages(['asset' => 'This asset contribution is not pending approval.']);
            }

            $this->contributions->approve(
                Capital::whereKey($locked->capital_id)->firstOrFail(),
                $approver,
                function (Capital $capital) use ($locked, $approver): void {
                    $locked->update(['status' => 'active', 'journal_entry_id' => $capital->journal_entry_id]);
                    $total = (string) $locked->contribution_value;
                    $this->event($locked, 'contributed_as_capital', $approver, new: ['capital_id' => $capital->id, 'share_holder_id' => $locked->share_holder_id, 'share_holder' => $locked->shareHolder?->full_name, 'ledger_account' => $locked->ledgerAccount()?->label(), 'journal_entry_id' => $capital->journal_entry_id, 'valuation_method' => $locked->valuation_method], after: $total);
                    $this->event($locked, 'allocated_to_branch', $approver, new: ['branch_id' => $locked->branch_id, 'branch' => $locked->branch?->name, 'location' => $locked->location]);
                },
                'CAPITAL CONTRIBUTION (ASSET) - '.mb_strtoupper(mb_substr((string) $locked->name, 0, 80)).' - '.$locked->shareHolder?->full_name,
            );

            return $locked;
        });
    }

    /**
     * Reject a pending asset contribution: nothing was posted; the contribution is kept as rejected with the reason and the
     * asset gets status rejected (no further changes).
     *
     * @throws ValidationException
     */
    public function reject(Asset $asset, string $reason, Employee $employee): Asset
    {
        return DB::transaction(function () use ($asset, $reason, $employee): Asset {
            $locked = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== self::PENDING) {
                throw ValidationException::withMessages(['reason' => 'Only pending asset contributions can be rejected.']);
            }

            $this->contributions->reject(Capital::whereKey($locked->capital_id)->firstOrFail(), $reason, $employee, function () use ($locked, $reason, $employee): void {
                $locked->update(['status' => self::REJECTED]);
                $this->event($locked, 'contribution_rejected', $employee, ['status' => self::PENDING], ['status' => self::REJECTED], reason: $reason);
            });

            return $locked;
        });
    }

    /**
     * Edit descriptive fields only (location, notes, condition, description, type-specific identifiers).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Asset $asset, array $data, Employee $employee): Asset
    {
        $this->assertNotTerminal($asset);
        $config = $this->types->get($asset->asset_type);

        $changes = array_intersect_key($data, array_flip(['location', 'notes', 'description', 'condition']));
        if (! $config['condition']) {
            unset($changes['condition']);
        }
        if (array_key_exists('specifications', $data)) {
            $changes['specifications'] = $this->types->specifications($asset->asset_type, (array) $data['specifications']);
        }

        return DB::transaction(function () use ($asset, $changes, $employee): Asset {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $previous = [];
            $new = [];
            foreach ($changes as $field => $value) {
                if ($asset->{$field} != $value) {
                    $previous[$field] = $asset->{$field};
                    $new[$field] = $value;
                }
            }
            if ($new === []) {
                return $asset;
            }

            $asset->update($new);
            $this->event($asset, 'updated', $employee, $previous, $new);

            return $asset;
        });
    }

    public function transfer(Asset $asset, int $toBranchId, CarbonImmutable $date, string $reason, Employee $employee): Asset
    {
        $this->assertNotTerminal($asset);
        $to = Branch::where('company_id', $asset->company_id)->find($toBranchId)
            ?? throw ValidationException::withMessages(['to_branch_id' => 'Select a branch of this company']);

        return DB::transaction(function () use ($asset, $to, $date, $reason, $employee): Asset {
            $asset = Asset::whereKey($asset->id)->with('branch')->lockForUpdate()->firstOrFail();
            if ((int) $asset->branch_id === $to->id) {
                throw ValidationException::withMessages(['to_branch_id' => 'The asset is already allocated to this branch']);
            }

            $from = $asset->branch;
            $asset->update(['branch_id' => $to->id]);
            $this->event($asset, 'transferred', $employee, ['branch_id' => $from?->id, 'branch' => $from?->name], ['branch_id' => $to->id, 'branch' => $to->name, 'transfer_date' => $date->toDateString()], reason: $reason, occurredAt: $date->setTimeFrom(now()));

            return $asset;
        });
    }

    public function changeStatus(Asset $asset, string $status, string $reason, Employee $employee): Asset
    {
        $this->assertNotTerminal($asset);
        if (! array_key_exists($status, config('assets.statuses'))) {
            throw ValidationException::withMessages(['status' => 'Select a valid status']);
        }

        return DB::transaction(function () use ($asset, $status, $reason, $employee): Asset {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            if ($asset->status === $status) {
                throw ValidationException::withMessages(['status' => 'The asset already has this status']);
            }

            $previous = $asset->status;
            $asset->update(['status' => $status]);
            $event = match ($status) {
                'disposed' => 'disposed',
                'written_off' => 'written_off',
                'under_maintenance' => 'maintenance',
                default => 'status_changed',
            };
            $this->event($asset, $event, $employee, ['status' => $previous], ['status' => $status], reason: $reason);

            return $asset;
        });
    }

    /**
     * Memo revaluation: current value and history only. The contribution value and the ledger are unchanged.
     *
     * @param  array{new_value: string|float|int, valuation_date: string, valuation_method: string, valued_by?: string|null, valuation_reference?: string|null, reason: string}  $data
     */
    public function revalue(Asset $asset, array $data, Employee $employee): Asset
    {
        $this->assertNotTerminal($asset);
        $newValue = number_format((float) $data['new_value'], 2, '.', '');
        $date = CarbonImmutable::parse($data['valuation_date']);

        return DB::transaction(function () use ($asset, $data, $newValue, $date, $employee): Asset {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $before = (string) $asset->current_value;

            $asset->update(['current_value' => $newValue]);
            $this->event($asset, 'revalued', $employee, ['current_value' => $before], [
                'current_value' => $newValue,
                'valuation_method' => $data['valuation_method'],
                'valuation_date' => $date->toDateString(),
                'valued_by' => $data['valued_by'] ?? null,
                'valuation_reference' => $data['valuation_reference'] ?? null,
                'accounting' => 'memo only — contributed capital and ledger unchanged',
            ], $before, $newValue, $data['reason'], $date->setTimeFrom(now()));

            return $asset;
        });
    }

    /**
     * Correction of a wrongly recorded asset contribution: reverse its journal and mark contribution and asset reversed.
     */
    public function reverse(Asset $asset, string $reason, Employee $employee): Asset
    {
        return DB::transaction(function () use ($asset, $reason, $employee): Asset {
            $asset = Asset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $capital = Capital::whereKey($asset->capital_id)->lockForUpdate()->firstOrFail();

            if ($capital->isReversed() || $asset->status === 'reversed') {
                throw ValidationException::withMessages(['reason' => 'This asset contribution has already been reversed']);
            }
            if (! $capital->isPosted()) {
                throw ValidationException::withMessages(['reason' => 'Only approved asset contributions can be reversed']);
            }
            $linked = ShareTransaction::where('capital_id', $capital->id)->where('status', ShareTransaction::COMPLETED)->value('reference');
            if ($linked !== null) {
                throw ValidationException::withMessages(['reason' => "Shares were issued against this contribution ({$linked}); reverse that share transaction first"]);
            }

            $reversal = $capital->journalEntry === null ? null : $this->ledger->reverse($capital->journalEntry, $reason);
            $capital->update(['reversed_at' => now(), 'reversal_journal_entry_id' => $reversal?->id, 'reversal_reason' => $reason]);

            $previous = $asset->status;
            $asset->update(['status' => 'reversed']);
            $this->event($asset, 'contribution_reversed', $employee, ['status' => $previous, 'contribution_value' => (string) $asset->contribution_value], ['status' => 'reversed', 'reversal_journal_entry_id' => $reversal?->id, 'reversal_reference' => $reversal?->reference], (string) $asset->contribution_value, '0.00', $reason);

            return $asset;
        });
    }

    public function addDocument(Asset $asset, UploadedFile $file, string $documentType, Employee $employee): AssetDocument
    {
        if (! in_array($documentType, $this->types->documentTypes($asset->asset_type), true)) {
            throw ValidationException::withMessages(['document_type' => 'Select a document type for this asset type']);
        }

        $path = $file->store("assets/{$asset->company_id}/{$asset->id}", Asset::DISK);

        try {
            return DB::transaction(function () use ($asset, $file, $documentType, $employee, $path): AssetDocument {
                $document = AssetDocument::create([
                    'company_id' => $asset->company_id,
                    'asset_id' => $asset->id,
                    'document_type' => $documentType,
                    'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 191),
                    'path' => $path,
                    'mime' => (string) $file->getMimeType(),
                    'size' => (int) $file->getSize(),
                    'uploaded_by' => $employee->id,
                ]);
                $this->event($asset, 'document_uploaded', $employee, new: ['document_id' => $document->id, 'document_type' => $documentType, 'name' => $document->original_name]);

                return $document;
            });
        } catch (Throwable $exception) {
            Storage::disk(Asset::DISK)->delete($path);

            throw $exception;
        }
    }

    public function deleteDocument(AssetDocument $document, Employee $employee, ?string $reason = null): void
    {
        DB::transaction(function () use ($document, $employee, $reason): void {
            $this->event($document->asset, 'document_deleted', $employee, ['document_id' => $document->id, 'document_type' => $document->document_type, 'name' => $document->original_name], reason: $reason);
            $document->delete();
        });

        Storage::disk(Asset::DISK)->delete($document->path);
    }

    /**
     * AST-000001: next per-company sequence, assigned after insert while the company row is locked.
     */
    private function assignCode(Asset $asset): void
    {
        Company::whereKey($asset->company_id)->lockForUpdate()->first();
        $sequence = (int) Asset::where('company_id', $asset->company_id)->max('sequence') + 1;

        $asset->update([
            'sequence' => $sequence,
            'asset_code' => config('assets.code_prefix').str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        ]);
    }

    private function assertNotTerminal(Asset $asset): void
    {
        if ($asset->status === self::PENDING) {
            throw ValidationException::withMessages(['status' => 'This asset contribution is pending approval and cannot be changed yet']);
        }
        if ($asset->status === self::REJECTED || $asset->isTerminal()) {
            throw ValidationException::withMessages(['status' => 'This asset is '.str_replace('_', ' ', $asset->status).' and can no longer be changed']);
        }
    }

    /**
     * @param  array<string, mixed>|null  $previous
     * @param  array<string, mixed>|null  $new
     */
    private function event(
        Asset $asset,
        string $event,
        Employee $employee,
        ?array $previous = null,
        ?array $new = null,
        ?string $before = null,
        ?string $after = null,
        ?string $reason = null,
        ?CarbonImmutable $occurredAt = null,
    ): AssetEvent {
        return AssetEvent::create([
            'company_id' => $asset->company_id,
            'asset_id' => $asset->id,
            'event' => $event,
            'occurred_at' => $occurredAt ?? now(),
            'employee_id' => $employee->id,
            'previous_value' => $previous,
            'new_value' => $new,
            'amount_before' => $before,
            'amount_after' => $after,
            'reason' => $reason,
        ]);
    }
}
