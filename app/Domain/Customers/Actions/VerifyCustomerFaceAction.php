<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\FaceScanStatus;
use App\Domain\Customers\Services\KycDocumentStorage;
use App\Domain\Customers\Services\KycEvaluator;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\FaceScan;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * POST /customers/{customer}/face-verify — spec §15.1.
 *
 * Records a biometric verification event: the capture goes to the private KYC
 * disk, the scanner's measurements go to `face_scans`, and the customer's
 * summary columns are updated to point at it.
 *
 * Two rules govern this, and both are the opposite of what the code used to do:
 *
 *   1. **Nothing is destroyed.** A re-scan used to delete the previous image
 *      the moment it succeeded. That erased the only evidence of what the
 *      record looked like before — the exact thing a fraud investigation asks
 *      for. Superseded scans keep their row and their file; `is_active` moves.
 *
 *   2. **Only a passing scan verifies.** `face_verified_at` follows the
 *      verdict in both directions. If a re-scan fails, the customer stops
 *      being face-verified and KycEvaluator will correctly regress their KYC
 *      status — which is what a failed identity check means, however
 *      inconvenient. The image still becomes the active one, because it is the
 *      most recent thing known about this person's face and hiding it would
 *      make the profile disagree with the record.
 */
final class VerifyCustomerFaceAction
{
    public function __construct(
        private readonly KycDocumentStorage $storage,
        private readonly KycEvaluator $kyc,
        private readonly AuditLogger $audit,
        private readonly Request $request,
    ) {}

    /**
     * @param array<string, mixed> $report The validated scanner measurements —
     *                                     see FaceVerifyRequest::report().
     */
    public function handle(Customer $customer, UploadedFile $capture, array $report, User $actor): Customer
    {
        return DB::transaction(function () use ($customer, $capture, $report, $actor): Customer {
            $previous = $customer->activeFaceScan;

            $path = $this->storage->store($customer, $capture, 'liveness');

            /** @var FaceScanStatus $status */
            $status = $report['status'];
            $scannedAt = Date::now();

            // Exactly one active scan per customer. Cleared first so the
            // invariant holds even if an earlier write left two behind.
            $customer->faceScans()->where('is_active', true)->update(['is_active' => false]);

            /* Written out rather than spread: the spread erased the key type
               (`array<string, mixed>`), and listing them keeps what this
               endpoint actually persists readable at the call site. */
            $scan = FaceScan::create([
                'status' => $status,
                'quality_score' => $report['quality_score'],
                'brightness_score' => $report['brightness_score'],
                'blur_score' => $report['blur_score'],
                'distance_score' => $report['distance_score'],
                'centering_score' => $report['centering_score'],
                'eyes_open_score' => $report['eyes_open_score'],
                'scanner_version' => $report['scanner_version'],
                'liveness_passed' => $report['liveness_passed'],
                'pose_sequence_completed' => $report['pose_sequence_completed'],
                'checks' => $report['checks'],
                'capture_device' => $report['capture_device'],
                'capture_resolution' => $report['capture_resolution'],
                'capture_duration_ms' => $report['capture_duration_ms'],
                'reason' => $report['reason'],
                'customer_id' => $customer->getKey(),
                'photo_path' => $path,
                'scanned_by' => $actor->getKey(),
                'scanned_at' => $scannedAt,
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->userAgent(),
                'is_active' => true,
            ]);

            $customer->update([
                'photo_path' => $path,
                'active_face_scan_id' => $scan->getKey(),
                'face_scan_status' => $status,
                'face_scan_quality' => $report['quality_score'],
                'face_scan_version' => $report['scanner_version'],
                'face_scanned_at' => $scannedAt,
                'face_scanned_by' => $actor->getKey(),
                'face_verified_at' => $status->isPassed() ? $scannedAt : null,
            ]);

            $customer->load('bankDetails');
            $this->kyc->refresh($customer);

            /*
             * The audit entry names both images by scan id rather than by
             * path: the path is a private-disk detail that never leaves the
             * application, and the id is what the history endpoint resolves.
             */
            $this->audit->log(
                AuditAction::CustomerFaceScanned,
                $customer,
                before: $previous === null ? null : [
                    'face_scan_id' => $previous->getKey(),
                    'status' => $previous->status->value,
                    'quality_score' => $previous->quality_score,
                    'scanned_at' => $previous->scanned_at->toIso8601String(),
                ],
                after: [
                    'face_scan_id' => $scan->getKey(),
                    'status' => $status->value,
                    'quality_score' => $scan->quality_score,
                    'liveness_passed' => $scan->liveness_passed,
                    'pose_sequence_completed' => $scan->pose_sequence_completed,
                    'scanner_version' => $scan->scanner_version,
                    'capture_device' => $scan->capture_device,
                    'capture_resolution' => $scan->capture_resolution,
                    'capture_duration_ms' => $scan->capture_duration_ms,
                    'reason' => $scan->reason,
                    'checks' => $scan->checks,
                    'kyc_status' => $customer->kyc_status->value,
                ],
                actor: $actor,
            );

            /* A scan happening and a scan completing KYC are two different
               facts. The older event is still written, because the customer
               timeline reads it. */
            if ($status->isPassed()) {
                $this->audit->log(
                    AuditAction::CustomerKycVerified,
                    $customer,
                    after: ['step' => 'face', 'kyc_status' => $customer->kyc_status->value],
                    actor: $actor,
                );
            }

            return $customer->fresh(['category', 'branch', 'activeFaceScan', 'faceScanOperator']);
        });
    }

    /** The column is VARCHAR(255); a truncated agent beats a failed insert. */
    private function userAgent(): ?string
    {
        $agent = $this->request->userAgent();

        return $agent === null ? null : mb_substr($agent, 0, 255);
    }
}
