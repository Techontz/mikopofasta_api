<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\DTOs\GuarantorData;
use App\Domain\Customers\DTOs\NextOfKinData;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\NextOfKin;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Guarantors and next-of-kin attached to a customer profile.
 *
 * These are collected by the registration wizard and remain editable
 * afterwards through the profile panels, exactly as the frontend's
 * addGuarantor / removeGuarantor / addNextOfKin / removeNextOfKin actions do.
 *
 * Every change is audited. §6 requires at least one guarantor before a loan
 * may progress, so removing one changes what a customer is eligible for — and
 * a change to somebody's borrowing capacity should never be the one thing
 * nobody can account for afterwards. The removal snapshot records who the
 * guarantor was, because the row itself is gone by the time anyone asks.
 */
final class ManageCustomerRelationsAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function addGuarantor(Customer $customer, GuarantorData $data, ?User $actor = null): Guarantor
    {
        return DB::transaction(function () use ($customer, $data, $actor): Guarantor {
            $guarantor = $customer->guarantors()->create([
                'name' => $data->name,
                'phone' => $data->phone,
                'nida_number' => $data->nidaNumber,
                'relationship' => $data->relationship,
                'address' => $data->address,
                'occupation' => $data->occupation,
            ]);

            $this->audit->log(
                AuditAction::GuarantorAdded,
                $customer,
                after: $this->guarantorSnapshot($guarantor),
                actor: $actor,
            );

            return $guarantor;
        });
    }

    public function removeGuarantor(Guarantor $guarantor, ?User $actor = null): void
    {
        DB::transaction(function () use ($guarantor, $actor): void {
            $customer = $guarantor->customer;

            // Snapshotted BEFORE the delete: afterwards there is nothing left
            // to describe who was removed.
            $before = $this->guarantorSnapshot($guarantor);

            $guarantor->delete();

            $this->audit->log(
                AuditAction::GuarantorRemoved,
                $customer,
                before: $before,
                actor: $actor,
            );
        });
    }

    public function addNextOfKin(Customer $customer, NextOfKinData $data, ?User $actor = null): NextOfKin
    {
        return DB::transaction(function () use ($customer, $data, $actor): NextOfKin {
            $kin = $customer->nextOfKin()->create([
                'name' => $data->name,
                'relationship' => $data->relationship,
                'phone' => $data->phone,
                'address' => $data->address,
            ]);

            $this->audit->log(
                AuditAction::NextOfKinAdded,
                $customer,
                after: $this->kinSnapshot($kin),
                actor: $actor,
            );

            return $kin;
        });
    }

    public function removeNextOfKin(NextOfKin $kin, ?User $actor = null): void
    {
        DB::transaction(function () use ($kin, $actor): void {
            $customer = $kin->customer;
            $before = $this->kinSnapshot($kin);

            $kin->delete();

            $this->audit->log(
                AuditAction::NextOfKinRemoved,
                $customer,
                before: $before,
                actor: $actor,
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function guarantorSnapshot(Guarantor $guarantor): array
    {
        return [
            'guarantor_id' => $guarantor->getKey(),
            'name' => $guarantor->name,
            'phone' => $guarantor->phone,
            'relationship' => $guarantor->relationship,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kinSnapshot(NextOfKin $kin): array
    {
        return [
            'next_of_kin_id' => $kin->getKey(),
            'name' => $kin->name,
            'phone' => $kin->phone,
            'relationship' => $kin->relationship,
        ];
    }
}
