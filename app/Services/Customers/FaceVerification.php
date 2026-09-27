<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\FaceScan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Records a browser liveness scan (CUSTOMER_MODULE_IMPLEMENTATION.md §5.7). History is kept; only the latest
 * scan is active. A passed scan verifies the customer; a failed one un-verifies.
 */
class FaceVerification
{
    public function __construct(
        private KycStatusCalculator $kyc,
        private CustomerRegistrar $registrar,
    ) {}

    /**
     * @param  array<string, mixed>  $report  validated camelCase report
     */
    public function record(Customer $customer, UploadedFile $capture, array $report, Employee $actor, ?string $ipAddress, ?string $userAgent): FaceScan
    {
        $path = $capture->store('customers/'.$customer->id.'/face-scans', FaceScan::DISK);

        try {
            return DB::transaction(function () use ($customer, $path, $report, $actor, $ipAddress, $userAgent): FaceScan {
                $scannedAt = now();
                $passed = $report['status'] === 'passed';

                $customer->faceScans()->where('is_active', true)->update(['is_active' => false]);

                $scan = $customer->faceScans()->create([
                    'status' => $report['status'],
                    'quality_score' => (int) $report['qualityScore'],
                    'brightness_score' => (int) $report['brightnessScore'],
                    'blur_score' => (int) $report['blurScore'],
                    'distance_score' => (int) $report['distanceScore'],
                    'centering_score' => (int) $report['centeringScore'],
                    'eyes_open_score' => (int) $report['eyesOpenScore'],
                    'scanner_version' => $report['scannerVersion'],
                    'liveness_passed' => (bool) $report['livenessPassed'],
                    'pose_sequence_completed' => (bool) $report['poseSequenceCompleted'],
                    'checks' => collect(FaceScan::CHECKS)->mapWithKeys(fn (string $check): array => [$check => (bool) $report['checks'][$check]])->all(),
                    'capture_device' => $report['captureDevice'] ?? null,
                    'capture_resolution' => $report['captureResolution'] ?? null,
                    'capture_duration_ms' => isset($report['captureDurationMs']) ? (int) $report['captureDurationMs'] : null,
                    'reason' => $report['reason'] ?? null,
                    'photo_path' => $path,
                    'scanned_by' => $actor->id,
                    'scanned_at' => $scannedAt,
                    'ip_address' => $ipAddress,
                    'user_agent' => $userAgent,
                    'is_active' => true,
                ]);

                $wasVerified = $customer->face_verified_at !== null;
                $customer->forceFill([
                    'photo_path' => $path,
                    'active_face_scan_id' => $scan->id,
                    'face_scan_status' => $scan->status,
                    'face_scan_quality' => $scan->quality_score,
                    'face_scan_version' => $scan->scanner_version,
                    'face_scanned_at' => $scannedAt,
                    'face_scanned_by' => $actor->id,
                    'face_verified_at' => $passed ? $scannedAt : null,
                ])->save();

                $status = $this->kyc->refresh($customer);

                $this->registrar->audit($customer, 'Customer.face_scanned', [
                    'face_scan_id' => $scan->id,
                    'status' => $scan->status,
                    'quality_score' => $scan->quality_score,
                    'kyc_status' => $status,
                ], ['face_verified' => $wasVerified], $actor);

                return $scan;
            });
        } catch (\Throwable $exception) {
            Storage::disk(FaceScan::DISK)->delete($path);

            throw $exception;
        }
    }
}
