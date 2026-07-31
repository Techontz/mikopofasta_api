<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\DTOs\BankDetailsData;
use App\Domain\Customers\Services\KycEvaluator;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * POST /customers/{customer}/additional-data — spec §15.1.
 *
 * Bank details, marital status and structured residence: precisely the three
 * things the KYC checklist's "additionalDataComplete" item depends on. The
 * registration wizard supplies them up front; this endpoint exists so they can
 * be corrected afterwards, which the frontend currently has no screen for
 * (readiness report gap 7).
 */
final class UpdateCustomerAdditionalDataAction
{
    public function __construct(
        private readonly KycEvaluator $kyc,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(Customer $customer, array $payload, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $payload, $actor): Customer {
            $before = [
                'marital_status' => $customer->marital_status?->value,
                'region_id' => $customer->region_id,
                'residence_type' => $customer->residence_type?->value,
            ];

            $customer->update(array_filter([
                'marital_status' => $payload['maritalStatus'] ?? null,
                'region_id' => $payload['regionId'] ?? null,
                'district_id' => $payload['districtId'] ?? null,
                'ward_id' => $payload['wardId'] ?? null,
                'street_id' => $payload['streetId'] ?? null,
                'residence_type' => $payload['residenceType'] ?? null,
            ], static fn (mixed $v): bool => $v !== null));

            if (isset($payload['bankDetails']) && is_array($payload['bankDetails'])) {
                $bank = BankDetailsData::fromArray($payload['bankDetails']);

                // One set of bank details per customer (§2.4 has_one).
                $customer->bankDetails()->updateOrCreate(
                    ['customer_id' => $customer->getKey()],
                    [
                        'bank_name' => $bank->bankName,
                        'account_number' => $bank->accountNumber,
                        'account_name' => $bank->accountName,
                        'phone_number' => $bank->phoneNumber,
                        'check_number' => $bank->checkNumber,
                    ],
                );
            }

            // Supplying the missing pieces can complete KYC; removing them
            // could equally un-complete it. Always re-derive.
            $customer->load('bankDetails');
            $this->kyc->refresh($customer);

            $this->audit->log(
                AuditAction::CustomerUpdated,
                $customer,
                before: $before,
                after: [
                    'marital_status' => $customer->marital_status?->value,
                    'region_id' => $customer->region_id,
                    'residence_type' => $customer->residence_type?->value,
                    'kyc_status' => $customer->kyc_status->value,
                ],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch', 'bankDetails']);
        });
    }
}
