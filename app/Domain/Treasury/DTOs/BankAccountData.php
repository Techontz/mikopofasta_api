<?php

declare(strict_types=1);

namespace App\Domain\Treasury\DTOs;

use App\Domain\Treasury\Enums\AccountChannelType;
use App\Domain\Treasury\Enums\AccountUsage;
use App\Domain\Treasury\Enums\Currency;
use App\Enums\ActiveStatus;
use App\Models\MasterData\Bank;
use App\Models\MasterData\MobileMoneyProvider;

/**
 * One registered company money account — Bank → Register Account.
 *
 * ## No branch
 *
 * `branchId` used to be here. A company's bank account is not a branch's
 * property: the money sits with the bank, in the company's name, and any
 * branch's collections may land in it. Tying it to one branch made a
 * company-level channel look like departmental property and, worse, made the
 * opening-balance journal line carry a branch that had nothing to do with it.
 *
 * Branch remains everywhere it is genuinely organizational — who may see what,
 * which branch a customer belongs to, which branch a loan was written at. It is
 * gone from THIS feature only, where it was wrong.
 *
 * ## Provider, not free text
 *
 * `bankId` / `mobileMoneyProviderId` point at the master data lists the
 * institution already maintains. `bankName` is still carried because it is what
 * the account is labelled with, and an account registered before the list
 * existed must keep reading correctly rather than becoming nameless.
 */
final readonly class BankAccountData
{
    public function __construct(
        public AccountChannelType $accountType,
        public AccountUsage $usage,
        public string $bankName,
        public ?int $bankId,
        public ?int $mobileMoneyProviderId,
        public string $accountName,
        public string $accountNumber,
        public Currency $currency,
        public string $openingBalance,
        public ActiveStatus $status,
        public ?string $description,
    ) {}

    /** @param array<string, mixed> $validated */
    public static function fromArray(array $validated): self
    {
        $blankToNull = static function (mixed $v): ?string {
            $s = trim((string) ($v ?? ''));

            return $s === '' ? null : $s;
        };

        $type = AccountChannelType::from((string) ($validated['accountType'] ?? AccountChannelType::Bank->value));

        $bankId = isset($validated['bankId']) ? (int) $validated['bankId'] : null;
        $providerId = isset($validated['mobileMoneyProviderId'])
            ? (int) $validated['mobileMoneyProviderId']
            : null;

        /*
         * Exactly one provider applies, and the other is cleared rather than
         * trusted to be absent. Switching an account from Bank to MNO on the
         * edit form leaves the old id in the payload, and keeping it would
         * leave a wallet pointing at a bank — which the uniqueness key reads,
         * so the row would compare as a different physical account than it is.
         */
        if ($type === AccountChannelType::Bank) {
            $providerId = null;
        } else {
            $bankId = null;
        }

        /*
         * The label. Taken from master data when a provider was chosen, so the
         * stored text cannot drift from the list; falls back to whatever the
         * caller sent for an account that names no provider.
         */
        $name = $blankToNull($validated['bankName'] ?? null) ?? '';

        if ($bankId !== null) {
            $name = Bank::query()->whereKey($bankId)->value('name') ?? $name;
        } elseif ($providerId !== null) {
            $name = MobileMoneyProvider::query()->whereKey($providerId)->value('name') ?? $name;
        }

        return new self(
            accountType: $type,
            usage: AccountUsage::from((string) ($validated['usage'] ?? AccountUsage::Both->value)),
            bankName: trim($name),
            bankId: $bankId,
            mobileMoneyProviderId: $providerId,
            accountName: trim((string) $validated['accountName']),
            accountNumber: trim((string) $validated['accountNumber']),
            currency: Currency::from((string) $validated['currency']),
            // A string all the way to the DECIMAL column.
            openingBalance: (string) ($validated['openingBalance'] ?? '0'),
            status: ActiveStatus::from((string) $validated['status']),
            description: $blankToNull($validated['description'] ?? null),
        );
    }

    /**
     * The columns a create or update writes. Never the generated keys.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'account_type' => $this->accountType,
            'usage' => $this->usage,
            'bank_name' => $this->bankName,
            'bank_id' => $this->bankId,
            'mobile_money_provider_id' => $this->mobileMoneyProviderId,
            'account_number' => $this->accountNumber,
            'account_name' => $this->accountName,
            'currency' => $this->currency,
            'opening_balance' => $this->openingBalance,
            'description' => $this->description,
            'status' => $this->status,
        ];
    }
}
