<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Domain\Customers\Services\KycEvaluator;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /customers/{customer}/face-verify — spec §15.1.
 *
 * Stores the liveness capture on the private KYC disk and stamps
 * `face_verified_at`. The image is regulated biometric data: it never touches
 * the public disk and its path is never returned.
 */
final class VerifyCustomerFaceAction
{
    public function __construct(
        private readonly KycDocumentStorage $storage,
        private readonly KycEvaluator $kyc,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(Customer $customer, UploadedFile $capture, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $capture, $actor): Customer {
            $previousPath = $customer->photo_path;

            $path = $this->storage->store($customer, $capture, 'liveness');

            $customer->update([
                'photo_path' => $path,
                'face_verified_at' => Date::now(),
            ]);

            // A re-capture replaces the old image rather than accumulating
            // biometric copies nothing references.
            if ($previousPath !== null && $previousPath !== $path) {
                $this->storage->delete($previousPath);
            }

            $customer->load('bankDetails');
            $this->kyc->refresh($customer);

            $this->audit->log(
                AuditAction::CustomerKycVerified,
                $customer,
                after: ['step' => 'face', 'kyc_status' => $customer->kyc_status->value],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch']);
        });
    }
}
