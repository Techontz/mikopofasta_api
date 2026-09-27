<?php

namespace App\Http\Resources\Api\V1\Customers;

use App\Models\FaceScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Face scan resource (CUSTOMER_MODULE_IMPLEMENTATION.md §6). `imageUrl` is relative to the API base.
 *
 * @mixin FaceScan
 */
class FaceScanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customerId' => (int) $this->customer_id,
            'status' => $this->status,
            'qualityScore' => (int) $this->quality_score,
            'brightnessScore' => (int) $this->brightness_score,
            'blurScore' => (int) $this->blur_score,
            'distanceScore' => (int) $this->distance_score,
            'centeringScore' => (int) $this->centering_score,
            'eyesOpenScore' => (int) $this->eyes_open_score,
            'scannerVersion' => $this->scanner_version,
            'livenessPassed' => $this->liveness_passed,
            'poseSequenceCompleted' => $this->pose_sequence_completed,
            'checks' => (object) ($this->checks ?? []),
            'captureDevice' => $this->capture_device,
            'captureResolution' => $this->capture_resolution,
            'captureDurationMs' => $this->capture_duration_ms === null ? null : (int) $this->capture_duration_ms,
            'reason' => $this->reason,
            'scannedById' => $this->scanned_by === null ? null : (int) $this->scanned_by,
            'scannedByName' => $this->whenLoaded('scanner', fn () => $this->scanner?->full_name, null),
            'scannedAt' => $this->scanned_at?->toIso8601String(),
            'ipAddress' => $this->ip_address,
            'userAgent' => $this->user_agent,
            'isActive' => $this->is_active,
            'imageUrl' => "customers/{$this->customer_id}/face-scans/{$this->id}/image",
        ];
    }
}
