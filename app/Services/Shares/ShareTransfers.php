<?php

namespace App\Services\Shares;

use App\Enums\ShareTransactionType;
use App\Models\Employee;
use App\Models\ShareHolder;
use App\Models\ShareTransaction;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Transfer existing shares between shareholders. The total issued shares never change (before total = after total).
 *
 * The source and destination positions are locked FOR UPDATE (in shareholder id order) inside one database transaction
 * and the source balance is re-checked under the lock, so concurrent transfers cannot overspend a holding. A transfer
 * posts no company ledger entry (company cash is unchanged); an optional consideration is stored for information only.
 */
class ShareTransfers
{
    public function __construct(private readonly ShareRegister $register) {}

    /**
     * @return array{transaction: ShareTransaction, created: bool}
     */
    public function transfer(
        ShareHolder $from,
        ShareHolder $to,
        int $shares,
        ?float $considerationPerShare,
        ?CarbonInterface $date,
        ?string $notes,
        Employee $performedBy,
        ?string $idempotencyKey = null,
        ?UploadedFile $document = null,
    ): array {
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_share_holder_id' => 'The receiving shareholder must be different from the transferring shareholder']);
        }
        if ((int) $from->company_id !== (int) $to->company_id) {
            throw ValidationException::withMessages(['to_share_holder_id' => 'The shareholder does not belong to this company']);
        }

        $companyId = (int) $from->company_id;

        return $this->register->idempotent($idempotencyKey, $companyId, [ShareTransactionType::Transfer], $from->id, $to->id, $shares, function () use ($from, $to, $shares, $considerationPerShare, $date, $notes, $performedBy, $idempotencyKey, $document, $companyId): ShareTransaction {
            $structure = $this->register->requireStructure($companyId);
            $at = $this->register->moment($date, $structure, 'transfer_date');
            $this->register->assertNotBeforeLatest($companyId, $at, 'transfer_date');
            $price = $considerationPerShare === null ? null : round($considerationPerShare, 2);

            return $this->register->withDocument($document, $companyId, fn (array $documentColumns): ShareTransaction => $this->register->record(
                $structure,
                ShareTransactionType::Transfer,
                $from,
                $to,
                $shares,
                $at,
                $performedBy,
                $documentColumns + [
                    'price_per_share' => $price,
                    'total_amount' => $price === null ? null : round($price * $shares, 2),
                    'notes' => $notes,
                    'idempotency_key' => $idempotencyKey,
                ],
            ));
        });
    }
}
