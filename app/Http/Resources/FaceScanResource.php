<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Http\Requests\Customers\FaceVerifyRequest;
use App\Models\FaceScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * One face scan, as the profile's Face Verification section reads it.
 *
 * `imageUrl` is a signed, expiring link to the download route — the stored
 * path is a private-disk detail and never leaves the application, exactly as
 * with customer documents and the liveness photo.
 *
 * `checks` is emitted with every key present, defaulting to false, so the
 * frontend can render a fixed checklist rather than whatever happened to be
 * stored. A missing key means "the scanner did not report it", which reads as
 * not passed — the safe direction for a KYC control.
 *
 * @mixin FaceScan
 */
final class FaceScanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $stored = $this->checks;

        return [
            'id' => (string) $this->id,
            'customerId' => (string) $this->customer_id,
            'status' => $this->status->value,

            'qualityScore' => $this->quality_score,
            'brightnessScore' => $this->brightness_score,
            'blurScore' => $this->blur_score,
            'distanceScore' => $this->distance_score,
            'centeringScore' => $this->centering_score,
            'eyesOpenScore' => $this->eyes_open_score,

            'scannerVersion' => $this->scanner_version,
            'livenessPassed' => $this->liveness_passed,
            'poseSequenceCompleted' => $this->pose_sequence_completed,

            'checks' => collect(FaceVerifyRequest::CHECKS)
                ->mapWithKeys(fn (string $check): array => [$check => (bool) ($stored[$check] ?? false)])
                ->all(),

            'captureDevice' => $this->capture_device,
            'captureResolution' => $this->capture_resolution,
            'captureDurationMs' => $this->capture_duration_ms,

            'reason' => $this->reason,

            'scannedById' => $this->scanned_by === null ? null : (string) $this->scanned_by,
            'scannedByName' => $this->whenLoaded('operator', fn (): ?string => $this->operator?->name),
            'scannedAt' => $this->scanned_at->toIso8601String(),

            'ipAddress' => $this->ip_address,
            'userAgent' => $this->user_agent,

            'isActive' => $this->is_active,

            'imageUrl' => URL::temporarySignedRoute(
                'api.v1.customers.face-scans.image',
                now()->addMinutes(KycDocumentStorage::URL_TTL_MINUTES),
                ['customer' => $this->customer_id, 'faceScan' => $this->id],
            ),
        ];
    }
}
