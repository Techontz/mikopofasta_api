<?php

namespace Tests\Feature\Api\Customers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Models\FaceScan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Customers\Concerns\BuildsCustomerModule;
use Tests\TestCase;

/**
 * KYC attachments (§8.5) and face verification (§8.7), server side.
 */
class CustomerDocumentAndFaceApiTest extends TestCase
{
    use BuildsCustomerModule, RefreshDatabase;

    public function test_kyc_attachment_is_stored_privately_and_downloaded_only_through_the_endpoint(): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->assertCreated()->json('data.id');

        $response = $this->post("/api/v1/customers/{$customerId}/documents", ['documentType' => 'kyc_attachment', 'file' => UploadedFile::fake()->create('kyc.pdf', 2048, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.documentType', 'kyc_attachment')
            ->assertJsonPath('data.originalName', 'kyc.pdf')
            ->assertJsonPath('data.customerId', $customerId)
            ->assertJsonPath('data.uploadedBy', $admin->id)
            ->assertJsonStructure(['data' => ['id', 'customerId', 'documentType', 'filePath', 'originalName', 'mimeType', 'sizeBytes', 'uploadedBy', 'createdAt']]);

        $document = CustomerDocument::findOrFail($response->json('data.id'));
        Storage::disk('local')->assertExists($document->file_path);
        $this->assertStringNotContainsString('public', $document->file_path);
        $this->assertSame(2048 * 1024, $response->json('data.sizeBytes'));
        $this->assertTrue(AuditLog::where('action', 'Customer.document_uploaded')->where('auditable_id', $customerId)->exists());

        $this->getJson("/api/v1/customers/{$customerId}/documents")->assertOk()->assertJsonCount(1, 'data');
        $this->get("/api/v1/customers/{$customerId}/documents/{$document->id}/download")->assertOk()->assertDownload('kyc.pdf');

        $foreignBranch = Branch::factory()->create(['company_id' => $admin->company_id]);
        $outsider = $this->employeeWithRole($admin, 'loan_officer', $foreignBranch->id);
        $this->actingAs($outsider)->get("/api/v1/customers/{$customerId}/documents/{$document->id}/download", ['Accept' => 'application/json'])->assertNotFound();
        $this->actingAs($this->employeeWithRole($admin, 'teller'))->getJson("/api/v1/customers/{$customerId}/documents/{$document->id}/download")->assertForbidden();

        $this->actingAs($admin)->deleteJson("/api/v1/customers/{$customerId}/documents/{$document->id}")->assertOk();
        Storage::disk('local')->assertMissing($document->file_path);
    }

    public function test_document_type_and_file_rules(): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->json('data.id');
        $upload = fn (array $data) => $this->post("/api/v1/customers/{$customerId}/documents", $data, ['Accept' => 'application/json']);

        $upload([])->assertUnprocessable()->assertJsonValidationErrors(['documentType', 'file']);
        $upload(['documentType' => 'salary_slip', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertUnprocessable()->assertJsonPath('errors.documentType.0', 'That document type is not configured. Choose one from the list.');
        $upload(['documentType' => 'kyc_attachment', 'file' => UploadedFile::fake()->create('a.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'Documents must be a PDF or an image.');
        $upload(['documentType' => 'kyc_attachment', 'file' => UploadedFile::fake()->create('a.pdf', 10241, 'application/pdf')])
            ->assertUnprocessable()->assertJsonPath('errors.file.0', 'The document must not be larger than 10 MB.');

        foreach (['kyc.jpg' => 'image/jpeg', 'kyc.png' => 'image/png', 'kyc.webp' => 'image/webp'] as $name => $mime) {
            $file = str_ends_with($name, 'webp') ? UploadedFile::fake()->createWithContent($name, $this->webp()) : UploadedFile::fake()->image($name);
            $upload(['documentType' => 'kyc_attachment', 'file' => $file])->assertCreated();
        }
    }

    public function test_a_passed_scan_verifies_the_customer_and_completes_kyc(): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->assertJsonPath('data.kycStatus', 'incomplete')->json('data.id');

        $this->getJson("/api/v1/customers/{$customerId}/kyc-status")
            ->assertOk()
            ->assertJsonPath('data.kycStatus', 'incomplete')
            ->assertJsonPath('data.outstanding', ['Face verification']);

        $response = $this->post("/api/v1/customers/{$customerId}/face-verify", ['capture' => UploadedFile::fake()->image('capture.jpg', 1280, 720), 'reason' => 'Registration'] + $this->faceReport(), ['Accept' => 'application/json', 'User-Agent' => 'Scanner/1.0'])
            ->assertOk()
            ->assertJsonPath('data.faceScanStatus', 'passed')
            ->assertJsonPath('data.faceScanQuality', 91)
            ->assertJsonPath('data.faceScanVersion', 'mediapipe-face-landmarker-0.10')
            ->assertJsonPath('data.faceScannedById', $admin->id)
            ->assertJsonPath('data.kycStatus', 'completed');
        $this->assertNotNull($response->json('data.faceVerifiedAt'));

        $scan = FaceScan::where('customer_id', $customerId)->firstOrFail();
        $this->assertTrue($scan->is_active);
        $this->assertSame([91, 80, 85, 90, 88, 97], [$scan->quality_score, $scan->brightness_score, $scan->blur_score, $scan->distance_score, $scan->centering_score, $scan->eyes_open_score]);
        $this->assertTrue($scan->liveness_passed);
        $this->assertTrue($scan->pose_sequence_completed);
        $this->assertTrue($scan->checks['poseDown']);
        $this->assertSame(['FaceTime HD Camera', '1280x720', 8450, 'Registration', 'Scanner/1.0'], [$scan->capture_device, $scan->capture_resolution, $scan->capture_duration_ms, $scan->reason, $scan->user_agent]);
        Storage::disk('local')->assertExists($scan->photo_path);

        $customer = Customer::findOrFail($customerId);
        $this->assertSame([$scan->id, $scan->photo_path, 'passed', 91], [$customer->active_face_scan_id, $customer->photo_path, $customer->face_scan_status, $customer->face_scan_quality]);
        $this->assertTrue(AuditLog::where('action', 'Customer.face_scanned')->where('auditable_id', $customerId)->exists());

        $this->getJson("/api/v1/customers/{$customerId}/face-scans")
            ->assertOk()
            ->assertJsonPath('data.0.id', $scan->id)
            ->assertJsonPath('data.0.checks.oneFaceDetected', true)
            ->assertJsonPath('data.0.imageUrl', "customers/{$customerId}/face-scans/{$scan->id}/image")
            ->assertJsonPath('data.0.scannedByName', $admin->full_name);
        $this->get("/api/v1/customers/{$customerId}/face-scans/{$scan->id}/image")->assertOk();
        $this->get("/api/v1/customers/{$customerId}/photo")->assertOk();
    }

    public function test_a_failed_scan_is_recorded_without_verifying_and_a_failed_rescan_unverifies(): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->json('data.id');
        $scan = fn (string $status) => $this->post("/api/v1/customers/{$customerId}/face-verify", ['capture' => UploadedFile::fake()->image('capture.png')] + $this->faceReport($status), ['Accept' => 'application/json']);

        $scan('failed')->assertOk()->assertJsonPath('data.faceVerifiedAt', null)->assertJsonPath('data.faceScanStatus', 'failed')->assertJsonPath('data.kycStatus', 'incomplete');
        $scan('passed')->assertOk()->assertJsonPath('data.kycStatus', 'completed');
        $scan('failed')->assertOk()->assertJsonPath('data.faceVerifiedAt', null)->assertJsonPath('data.kycStatus', 'incomplete');

        $this->assertSame(3, FaceScan::where('customer_id', $customerId)->count());
        $this->assertSame(1, FaceScan::where('customer_id', $customerId)->where('is_active', true)->count());
        $this->assertSame('failed', FaceScan::where('customer_id', $customerId)->where('is_active', true)->value('status'));
    }

    public function test_invalid_captures_reports_and_scores_are_refused(): void
    {
        Storage::fake('local');
        $admin = $this->signInAdmin();
        $this->seedCustomerModule($admin);
        $customerId = $this->postJson('/api/v1/customers', $this->registrationPayload($admin))->json('data.id');
        $scan = fn (array $data) => $this->post("/api/v1/customers/{$customerId}/face-verify", $data, ['Accept' => 'application/json']);

        $scan($this->faceReport())->assertUnprocessable()->assertJsonPath('errors.capture.0', 'A liveness capture is required.');
        $scan(['capture' => UploadedFile::fake()->create('capture.pdf', 10, 'application/pdf')] + $this->faceReport())
            ->assertUnprocessable()->assertJsonPath('errors.capture.0', 'The liveness capture must be an image.');
        $scan(['capture' => UploadedFile::fake()->image('capture.jpg')->size(5121)] + $this->faceReport())
            ->assertUnprocessable()->assertJsonPath('errors.capture.0', 'The liveness capture must not be larger than 5 MB.');

        $report = $this->faceReport();
        unset($report['checks']['poseLeft']);
        $scan(['capture' => UploadedFile::fake()->image('capture.jpg')] + $report)
            ->assertUnprocessable()->assertJsonPath('errors.checks.0', 'The scanner did not report every check. The scan cannot be recorded.');

        $scan(['capture' => UploadedFile::fake()->image('capture.jpg')] + $this->faceReport('passed', ['qualityScore' => 101, 'blurScore' => -1, 'status' => 'maybe', 'captureResolution' => '1280 by 720', 'captureDurationMs' => 3600001, 'scannerVersion' => '']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.captureResolution.0', 'The capture resolution must look like 1280x720.')
            ->assertJsonValidationErrors(['qualityScore', 'blurScore', 'status', 'captureDurationMs', 'scannerVersion']);

        $this->assertSame(0, FaceScan::count());
    }

    private function webp(): string
    {
        return (string) base64_decode('UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=');
    }
}
