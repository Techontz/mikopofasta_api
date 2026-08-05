<?php

declare(strict_types=1);

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\FaceScan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Face KYC as a module: the scan record, its history, and the audit trail.
 *
 * The behaviour these tests pin down is mostly about what does NOT happen —
 * a scan is not overwritten, a measurement is not invented, a failed check
 * does not leave the customer verified. Those are the properties that make a
 * biometric record worth having.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
    Storage::fake(KycDocumentStorage::DISK);
});

describe('recording a scan', function (): void {
    it('persists every measurement the scanner reported', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
            ->assertOk();

        $scan = FaceScan::query()->where('customer_id', $customer->id)->sole();

        expect($scan->status->value)->toBe('passed')
            ->and($scan->quality_score)->toBe(92)
            ->and($scan->brightness_score)->toBe(88)
            ->and($scan->blur_score)->toBe(90)
            ->and($scan->distance_score)->toBe(95)
            ->and($scan->centering_score)->toBe(93)
            ->and($scan->eyes_open_score)->toBe(99)
            ->and($scan->scanner_version)->toBe('mediapipe-face-landmarker@1.0.0')
            ->and($scan->liveness_passed)->toBeTrue()
            ->and($scan->pose_sequence_completed)->toBeTrue()
            ->and($scan->capture_device)->toBe('FaceTime HD Camera')
            ->and($scan->capture_resolution)->toBe('1280x720')
            ->and($scan->capture_duration_ms)->toBe(8420)
            ->and($scan->is_active)->toBeTrue()
            ->and($scan->checks)->toHaveCount(11)
            ->and($scan->checks['poseLeft'])->toBeTrue();
    });

    it('records who scanned, from where, and on what', function (): void {
        $officer = officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
            ->assertOk();

        $scan = FaceScan::query()->sole();

        expect($scan->scanned_by)->toBe($officer->getKey())
            ->and($scan->scanned_at)->not->toBeNull()
            ->and($scan->ip_address)->not->toBeNull();
    });

    it('mirrors the active scan onto the customer', function (): void {
        $officer = officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
            ->assertOk();

        $customer->refresh();
        $scan = FaceScan::query()->sole();

        expect($customer->active_face_scan_id)->toBe($scan->getKey())
            ->and($customer->face_scan_status->value)->toBe('passed')
            ->and($customer->face_scan_quality)->toBe(92)
            ->and($customer->face_scan_version)->toBe('mediapipe-face-landmarker@1.0.0')
            ->and($customer->face_scanned_by)->toBe($officer->getKey())
            ->and($customer->face_scanned_at)->not->toBeNull()
            ->and($customer->face_verified_at)->not->toBeNull();
    });

    it('refuses a capture with no measurements', function (): void {
        officerAt();
        $customer = registeredCustomer();

        // The old contract: an image and nothing else. It must no longer be
        // enough to record a verification.
        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", [
            'capture' => UploadedFile::fake()->image('liveness.jpg'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status', 'qualityScore', 'scannerVersion', 'livenessPassed']);

        expect(FaceScan::query()->count())->toBe(0)
            ->and($customer->refresh()->active_face_scan_id)->toBeNull()
            ->and($customer->face_scan_status)->toBeNull();
    });

    it('refuses a partial checklist', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $payload = faceScanPayload();
        unset($payload['checks']['poseDown']);

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['checks.poseDown']);
    });

    it('refuses a score outside 0–100', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload(['blurScore' => 140]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['blurScore']);
    });

    it('will not let the client name its own verification time', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $backdated = now()->subYears(3)->toIso8601String();

        $this->postJson(
            "/api/v1/customers/{$customer->id}/face-verify",
            faceScanPayload(['faceVerifiedAt' => $backdated, 'scannedAt' => $backdated]),
        )->assertOk();

        // The server stamps it. A client-supplied timestamp is ignored, not
        // honoured — a scan dated before the customer walked in is worthless.
        expect($customer->refresh()->face_verified_at->isToday())->toBeTrue()
            ->and(FaceScan::query()->sole()->scanned_at->isToday())->toBeTrue();
    });
});

describe('a failed scan', function (): void {
    it('does not leave the customer face-verified', function (): void {
        officerAt();
        $customer = registeredCustomer();

        expect($customer->face_verified_at)->not->toBeNull();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload([
            'status' => 'failed',
            'livenessPassed' => false,
            'poseSequenceCompleted' => false,
            'qualityScore' => 41,
            'checks' => ['poseUp' => false, 'poseDown' => false],
        ]))->assertOk();

        $customer->refresh();

        // KYC regresses, exactly as it does when bank details are removed.
        expect($customer->face_verified_at)->toBeNull()
            ->and($customer->face_scan_status->value)->toBe('failed')
            ->and($customer->kyc_status->value)->toBe('incomplete');
    });

    it('is still recorded, with its failing checks', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload([
            'status' => 'failed',
            'livenessPassed' => false,
            'checks' => ['poseUp' => false],
        ]))->assertOk();

        $scan = FaceScan::query()->sole();

        expect($scan->checks['poseUp'])->toBeFalse()
            ->and($scan->checks['poseLeft'])->toBeTrue()
            ->and($scan->liveness_passed)->toBeFalse();
    });
});

describe('history', function (): void {
    it('keeps superseded scans and their images', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();
        $first = FaceScan::query()->sole();

        $this->postJson(
            "/api/v1/customers/{$customer->id}/face-verify",
            faceScanPayload(['reason' => 'Photo no longer resembles the customer']),
        )->assertOk();

        $first->refresh();
        $second = FaceScan::query()->where('id', '!=', $first->id)->sole();

        expect(FaceScan::query()->count())->toBe(2)
            ->and($first->is_active)->toBeFalse()
            ->and($second->is_active)->toBeTrue()
            ->and($second->reason)->toBe('Photo no longer resembles the customer')
            ->and($customer->refresh()->active_face_scan_id)->toBe($second->getKey());

        // Both images survive. The old one used to be deleted on re-scan,
        // which destroyed the evidence a re-scan exists to be checked against.
        Storage::disk(KycDocumentStorage::DISK)->assertExists($first->photo_path);
        Storage::disk(KycDocumentStorage::DISK)->assertExists($second->photo_path);
        expect($first->photo_path)->not->toBe($second->photo_path);
    });

    it('lists the history newest first, with a signed image link on each', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();
        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload(['qualityScore' => 77]))->assertOk();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/face-scans")->assertOk();

        expect($response->json('data'))->toHaveCount(2)
            ->and($response->json('data.0.qualityScore'))->toBe(77)
            ->and($response->json('data.0.isActive'))->toBeTrue()
            ->and($response->json('data.1.isActive'))->toBeFalse()
            ->and($response->json('data.0.imageUrl'))->toContain('signature=')
            ->and($response->json('data.0.imageUrl'))->not->toContain('customers/'.$customer->id.'/liveness');
    });

    it('streams a scan image only under a valid signature', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();

        $url = $this->getJson("/api/v1/customers/{$customer->id}/face-scans")->json('data.0.imageUrl');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(explode('?', (string) $url)[0])->assertForbidden();
    });

    it('will not stream one customer’s scan under another customer’s URL', function (): void {
        officerAt();
        $customer = registeredCustomer();
        $other = registeredCustomer(['nidaNumber' => '19900101234599', 'phone' => '0755000099']);

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();
        $scan = FaceScan::query()->sole();

        $signed = URL::temporarySignedRoute(
            'api.v1.customers.face-scans.image',
            now()->addMinutes(5),
            ['customer' => $other->id, 'faceScan' => $scan->id],
        );

        $this->get($signed)->assertNotFound();
    });
});

describe('audit', function (): void {
    it('writes an audit event naming both the old and the new scan', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();
        $first = FaceScan::query()->sole();

        $this->postJson(
            "/api/v1/customers/{$customer->id}/face-verify",
            faceScanPayload(['reason' => 'Replaced at the customer’s request']),
        )->assertOk();

        $second = FaceScan::query()->where('id', '!=', $first->id)->sole();

        $entries = AuditLog::query()
            ->where('action', AuditAction::CustomerFaceScanned->value)
            ->orderBy('id')
            ->get();

        expect($entries)->toHaveCount(2)
            ->and($entries[0]->before_json)->toBeNull()
            ->and($entries[0]->after_json['face_scan_id'])->toBe($first->getKey())
            ->and($entries[1]->before_json['face_scan_id'])->toBe($first->getKey())
            ->and($entries[1]->after_json['face_scan_id'])->toBe($second->getKey())
            ->and($entries[1]->after_json['reason'])->toBe('Replaced at the customer’s request')
            ->and($entries[1]->ip_address)->not->toBeNull();
    });

    it('assembles a downloadable report for one scan', function (): void {
        $officer = officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();
        $scan = FaceScan::query()->sole();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/face-scans/{$scan->id}/audit")->assertOk();

        expect($response->json('data.customer.customerNumber'))->toBe($customer->customer_number)
            ->and($response->json('data.scan.qualityScore'))->toBe(92)
            ->and($response->json('data.scan.checks.poseStraight'))->toBeTrue()
            ->and($response->json('data.auditTrail'))->toHaveCount(1)
            ->and($response->json('data.auditTrail.0.operator'))->toBe($officer->name)
            ->and($response->json('data.generatedBy'))->toBe($officer->name);
    });

    it('refuses an audit report for a scan belonging to someone else', function (): void {
        officerAt();
        $customer = registeredCustomer();
        $other = registeredCustomer(['nidaNumber' => '19900101234588', 'phone' => '0755000088']);

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();
        $scan = FaceScan::query()->sole();

        $this->getJson("/api/v1/customers/{$other->id}/face-scans/{$scan->id}/audit")->assertNotFound();
    });
});

describe('access control', function (): void {
    it('needs customers.manage to record a scan', function (): void {
        officerAt();
        $customer = registeredCustomer();

        // A teller may look a customer up; they may not re-take their face.
        officerAt('Kakonko', App\Domain\Auth\Enums\RoleName::Teller);

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
            ->assertForbidden();

        expect(FaceScan::query()->count())->toBe(0);
    });

    it('does not expose another branch’s scan history', function (): void {
        officerAt();
        $customer = registeredCustomer();
        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();

        // A different branch's officer, on the same customer id.
        officerAt('Kigoma');

        $this->getJson("/api/v1/customers/{$customer->id}/face-scans")->assertForbidden();
    });
});
