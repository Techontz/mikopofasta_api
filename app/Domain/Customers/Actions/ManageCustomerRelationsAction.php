<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\DTOs\GuarantorData;
use App\Domain\Customers\DTOs\NextOfKinData;
use App\Domain\Customers\Services\KycDocumentStorage;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\NextOfKin;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

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
    public function __construct(
        private readonly AuditLogger $audit,
        /* The one code path that touches KYC files on disk — reused rather
           than reimplemented, so a guarantor's passport lands on the same
           private disk, under the same customer directory, as every other
           KYC document. */
        private readonly KycDocumentStorage $storage,
    ) {}

    /**
     * @param UploadedFile|null $passport The guarantor's photograph or scan,
     *                                    stored on the private `kyc` disk.
     */
    public function addGuarantor(
        Customer $customer,
        GuarantorData $data,
        ?User $actor = null,
        ?UploadedFile $passport = null,
        /* An import: the source whose passport is copied onto this record.
           Branch-checked by the caller before it gets here. */
        ?Guarantor $copyPassportFrom = null,
    ): Guarantor {
        /*
         * Written BEFORE the transaction, deliberately.
         *
         * A file write is not transactional: rolling the database back would
         * leave the object on disk regardless, so doing it inside would buy
         * nothing and would hold the transaction open for the length of an
         * upload. If the insert then fails, the cost is one orphaned file on a
         * private disk — which is recoverable — rather than a guarantor row
         * pointing at a path that was never written, which is not.
         */
        $stored = match (true) {
            $passport !== null => $this->storage->store($customer, $passport, 'guarantor-passport'),
            /* An import. The copy is made on the private disk rather than sent
               through the browser — see KycDocumentStorage::copy. */
            $copyPassportFrom !== null && $copyPassportFrom->passport_path !== null => $this->storage->copy(
                $customer,
                $copyPassportFrom->passport_path,
                'guarantor-passport',
            ),
            default => null,
        };

        try {
            return $this->insertGuarantor($customer, $data, $actor, $passport, $stored, $copyPassportFrom);
        } catch (Throwable $e) {
            /* The row never happened, so nothing references the file. Written
               outside the transaction, it has to be cleaned up by hand. */
            if ($stored !== null) {
                $this->storage->delete($stored);
            }

            throw $e;
        }
    }

    public function removeGuarantor(Guarantor $guarantor, ?User $actor = null): void
    {
        /* Read before the row goes: once it is deleted nothing points at the
           file any more, and an unreferenced KYC document on a private disk is
           one nobody will ever go looking for. */
        $passportPath = $guarantor->passport_path;

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

        /*
         * The file, only once the row is definitely gone and outside the
         * transaction — the same order ManageCustomerDocumentsAction::remove
         * uses. Deleting first would leave a surviving guarantor pointing at
         * nothing if the transaction rolled back, and of the two failures that
         * is the worse: an orphaned file on a private disk is recoverable, a
         * guarantor whose passport has silently vanished is not.
         *
         * Safe for an imported guarantor. KycDocumentStorage::copy() writes a
         * NEW file under the new customer's directory, so no two rows ever
         * share a path and removing one can never take the other's evidence
         * with it.
         */
        if ($passportPath !== null) {
            $this->storage->delete($passportPath);
        }
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
     * @param string|null $stored Path already written to the private disk.
     */
    private function insertGuarantor(
        Customer $customer,
        GuarantorData $data,
        ?User $actor,
        ?UploadedFile $passport,
        ?string $stored,
        ?Guarantor $copyPassportFrom,
    ): Guarantor {
        return DB::transaction(function () use ($customer, $data, $actor, $passport, $stored, $copyPassportFrom): Guarantor {
            $guarantor = $customer->guarantors()->create([
                'name' => $data->name,
                'phone' => $data->phone,
                'nida_number' => $data->nidaNumber,
                'gender' => $data->gender,
                'marital_status' => $data->maritalStatus,
                'relationship' => $data->relationship,
                'address' => $data->address,
                'occupation' => $data->occupation,
                'passport_path' => $stored,
                /* Display only — the name on disk is generated, because an
                   upload's own name is attacker-controlled (see
                   KycDocumentStorage). Taken from the upload, or carried over
                   from the record being imported: the copy describes the same
                   file. */
                'passport_original_name' => $passport?->getClientOriginalName()
                    ?? ($stored === null ? null : $copyPassportFrom?->passport_original_name),
                'passport_mime_type' => $passport?->getClientMimeType()
                    ?? ($stored === null ? null : $copyPassportFrom?->passport_mime_type),
                'passport_size_bytes' => $passport?->getSize()
                    ?? ($stored === null ? null : $copyPassportFrom?->passport_size_bytes),
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
