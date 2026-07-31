<?php

declare(strict_types=1);

namespace App\Domain\Treasury\Services;

use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Treasury\DTOs\FloatTransferData;
use App\Domain\Treasury\Enums\FloatTransferKind;
use App\Domain\Treasury\Exceptions\FloatTransferInvalidException;
use App\Models\Branch;
use App\Models\ChartOfAccount;
use App\Models\CompanyProfile;

/**
 * Works out which two ledger accounts a float transfer actually moves between.
 *
 * The screens speak in branches; the ledger speaks in accounts. This is the one
 * place that translation happens, so all three float kinds resolve the same way
 * and none of them can post to an account nobody meant.
 */
final class FloatAccountResolver
{
    public function __construct(private readonly AccountResolver $accounts) {}

    /**
     * @return array{0: ChartOfAccount, 1: ChartOfAccount}
     */
    public function resolve(FloatTransferData $data): array
    {
        return match ($data->kind) {
            // Company → branch: out of the head-office till, into the branch's.
            FloatTransferKind::CompanyToBranch => [
                $this->accounts->tellerCash($this->headOffice()),
                $this->accounts->tellerCash($this->branch($data->toBranchId, 'destination')),
            ],

            FloatTransferKind::BranchToBranch => [
                $this->accounts->tellerCash($this->branch($data->fromBranchId, 'source')),
                $this->accounts->tellerCash($this->branch($data->toBranchId, 'destination')),
            ],

            // Account → account: the caller names both sides outright.
            FloatTransferKind::AccountToAccount => [
                $this->account($data->fromAccountId, 'source'),
                $this->account($data->toAccountId, 'destination'),
            ],
        };
    }

    /** The branch company float is drawn from. */
    public function headOffice(): Branch
    {
        $branch = CompanyProfile::query()->first()?->headquartersBranch
            ?? Branch::query()->where('is_head_office', true)->first();

        if ($branch === null) {
            throw FloatTransferInvalidException::noHeadOffice();
        }

        return $branch;
    }

    private function branch(?int $id, string $side): Branch
    {
        $branch = $id === null ? null : Branch::query()->find($id);

        if ($branch === null) {
            throw FloatTransferInvalidException::missingBranch($side);
        }

        return $branch;
    }

    private function account(?int $id, string $side): ChartOfAccount
    {
        $account = $id === null ? null : ChartOfAccount::query()->find($id);

        if ($account === null) {
            throw FloatTransferInvalidException::missingAccount($side);
        }

        return $account;
    }
}
