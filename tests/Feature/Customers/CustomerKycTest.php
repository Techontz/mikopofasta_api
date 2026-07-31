<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Services\KycDocumentStorage;
use App\Enums\AuditAction;
use App\Models\AccountFreeze;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Region;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

describe('kyc checklist', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('reports the five-item checklist and overall status', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $response = $this->getJson("/api/v1/customers/{$customer->id}/kyc-status");

        $response->assertOk()
            ->assertJsonPath('data.isComplete', true)
            ->assertJsonPath('data.kycStatus', 'completed')
            ->assertJsonPath('data.checklist.nidaVerified', true)
            ->assertJsonPath('data.checklist.otpVerified', true)
            ->assertJsonPath('data.checklist.faceVerified', true)
            ->assertJsonPath('data.checklist.additionalDataComplete', true)
            ->assertJsonPath('data.checklist.categoryAssigned', true);
    });

    it('lists required documents still outstanding', function (): void {
        officerAt();
        $customer = registeredCustomer();

        // Boda Boda requires a driving licence and a motorcycle registration.
        expect($this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->json('data.missingDocuments'))
            ->toEqualCanonicalizing(['driving_license', 'motorcycle_registration']);
    });

    it('does not treat missing documents as a KYC blocker', function (): void {
        officerAt();
        $customer = registeredCustomer();

        // The frontend shows these as a warning, not a gate. Blocking here
        // would silently make customers loan-ineligible for a reason the UI
        // never explains.
        expect($this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->json('data.isComplete'))
            ->toBeTrue();
    });

    it('reports incomplete when bank details are absent, and completes when supplied', function (): void {
        officerAt();
        $customer = registeredCustomer(['bankDetails' => null]);

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.checklist.additionalDataComplete', false)
            ->assertJsonPath('data.isComplete', false);

        $this->postJson("/api/v1/customers/{$customer->id}/additional-data", [
            'bankDetails' => [
                'bankName' => 'NMB Bank',
                'accountNumber' => '01J9999999999',
                'accountName' => $customer->fullName(),
            ],
        ])->assertOk();

        // KYC is derived, so supplying the missing piece completes it without
        // anyone setting the column.
        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.isComplete', true)
            ->assertJsonPath('data.kycStatus', 'completed');
    });

    it('reflects loan eligibility', function (): void {
        officerAt();
        $customer = registeredCustomer();

        expect($this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->json('data.isLoanEligible'))->toBeTrue();

        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Fraud investigation'])->assertOk();

        // §9: a frozen customer is blocked from new loans.
        expect($this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->json('data.isLoanEligible'))->toBeFalse();
    });
});

describe('post-registration KYC edits', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('reassigns a category and revalidates against the new schema', function (): void {
        officerAt();
        $customer = registeredCustomer();
        $publicServant = CustomerCategory::query()->where('code', 'PUBLIC_SERVANT')->sole();

        // The old category's data does not satisfy the new one's schema.
        $this->putJson("/api/v1/customers/{$customer->id}/category", [
            'customerCategoryId' => $publicServant->getKey(),
            'dynamicFormData' => ['employer_name' => 'Ministry of Health'],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dynamicFormData.check_number', 'dynamicFormData.account_number']);

        $this->putJson("/api/v1/customers/{$customer->id}/category", [
            'customerCategoryId' => $publicServant->getKey(),
            'dynamicFormData' => [
                'employer_name' => 'Ministry of Health',
                'check_number' => 'CHK123456',
                'account_number' => '01J0000000001',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.customerCategoryId', (string) $publicServant->getKey());
    });

    it('sends a customer back to pending when moved into a category needing approval', function (): void {
        officerAt();
        $customer = registeredCustomer();
        expect($customer->approval_status)->toBe(CustomerApprovalStatus::NotRequired);

        $medium = CustomerCategory::query()->where('code', 'SME_MEDIUM')->sole();

        $this->putJson("/api/v1/customers/{$customer->id}/category", [
            'customerCategoryId' => $medium->getKey(),
            'dynamicFormData' => [
                'business_type' => 'Wholesale',
                'monthly_turnover' => 4200000,
                'years_in_business' => 6,
            ],
        ])
            ->assertOk()
            // Otherwise reassignment becomes a way to skip the approval the
            // category exists to demand.
            ->assertJsonPath('data.approvalStatus', 'pending');
    });

    it('updates address and marital status', function (): void {
        officerAt();
        $customer = registeredCustomer();
        $mbeya = Region::query()->where('name', 'Mbeya')->sole();

        $this->postJson("/api/v1/customers/{$customer->id}/additional-data", [
            'maritalStatus' => 'divorced',
            'regionId' => $mbeya->getKey(),
            'residenceType' => 'rented',
        ])
            ->assertOk()
            ->assertJsonPath('data.maritalStatus', 'divorced')
            ->assertJsonPath('data.regionId', (string) $mbeya->getKey())
            ->assertJsonPath('data.residenceType', 'rented');
    });
});

describe('face verification and documents', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
        Storage::fake(KycDocumentStorage::DISK);
    });

    it('stores the liveness capture on the private disk and stamps the timestamp', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", [
            'capture' => UploadedFile::fake()->image('liveness.jpg'),
        ])->assertOk();

        $customer->refresh();

        expect($customer->face_verified_at)->not->toBeNull()
            ->and($customer->photo_path)->toStartWith('customers/'.$customer->id.'/');

        Storage::disk(KycDocumentStorage::DISK)->assertExists($customer->photo_path);
    });

    it('never returns the stored path — only a signed URL', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", [
            'capture' => UploadedFile::fake()->image('liveness.jpg'),
        ])->assertOk();

        $photoPath = $this->getJson("/api/v1/customers/{$customer->id}")->json('data.photoPath');

        // Spec §1: signed, time-limited URLs only. Even a private path is
        // information about the layout of regulated storage.
        expect($photoPath)->toContain('/photo')
            ->and($photoPath)->toContain('signature=')
            ->and($photoPath)->not->toContain('customers/'.$customer->id.'/liveness');
    });

    it('rejects a non-image liveness capture', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", [
            'capture' => UploadedFile::fake()->create('malware.exe', 100),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['capture']);
    });

    it('uploads a document to the private disk and exposes only a signed URL', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $response = $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'driving_license',
            'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ]);

        $response->assertCreated()->assertJsonPath('data.documentType', 'driving_license');

        $document = $customer->documents()->sole();

        Storage::disk(KycDocumentStorage::DISK)->assertExists($document->file_path);

        expect($response->json('data.filePath'))
            ->toContain('signature=')
            ->and($response->json('data.filePath'))->not->toContain($document->file_path);
    });

    it('generates its own filename rather than trusting the upload', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'business_license',
            'file' => UploadedFile::fake()->create('../../../etc/passwd.pdf', 10, 'application/pdf'),
        ])->assertCreated();

        // A user-supplied filename is the classic path-traversal vector.
        expect($customer->documents()->sole()->file_path)
            ->toStartWith('customers/'.$customer->id.'/')
            ->not->toContain('..');
    });

    it('rejects an oversized or wrong-type document', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'business_license',
            'file' => UploadedFile::fake()->create('huge.pdf', 20480, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors(['file']);

        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'business_license',
            'file' => UploadedFile::fake()->create('script.js', 10, 'text/javascript'),
        ])->assertStatus(422)->assertJsonValidationErrors(['file']);
    });

    it('removes the file from disk when the document is deleted', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'driving_license',
            'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $document = $customer->documents()->sole();
        $path = $document->file_path;

        $this->deleteJson("/api/v1/customers/{$customer->id}/documents/{$document->id}")->assertOk();

        Storage::disk(KycDocumentStorage::DISK)->assertMissing($path);
        expect($customer->documents()->count())->toBe(0);
    });

    it('refuses an unsigned download', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'driving_license',
            'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $document = $customer->documents()->sole();

        // The signature IS the credential on these routes.
        $this->get("/api/v1/customers/{$customer->id}/documents/{$document->id}/download")
            ->assertForbidden();
    });

    it('serves the file over a valid signed URL', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'driving_license',
            'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $signed = $this->getJson("/api/v1/customers/{$customer->id}/documents")->json('data.0.filePath');

        $this->get($signed)->assertOk();
    });

    it('will not serve another customer document through the wrong customer URL', function (): void {
        officerAt();
        $first = registeredCustomer();
        $second = registeredCustomer(['nidaNumber' => '19900107777777', 'phone' => '0755127777']);

        $this->postJson("/api/v1/customers/{$first->id}/documents", [
            'documentType' => 'driving_license',
            'file' => UploadedFile::fake()->create('licence.pdf', 200, 'application/pdf'),
        ])->assertCreated();

        $document = $first->documents()->sole();

        // Route-model binding resolves the two independently.
        $this->deleteJson("/api/v1/customers/{$second->id}/documents/{$document->id}")->assertNotFound();
    });
});

describe('approval, freeze and status', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('approves a pending customer and records the decision', function (): void {
        $manager = officerAt('Kakonko', RoleName::BranchManager);

        $customer = registeredCustomer([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'SME_MEDIUM')->value('id'),
            'dynamicFormData' => ['business_type' => 'Wholesale', 'monthly_turnover' => 4200000, 'years_in_business' => 6],
        ]);

        $this->postJson("/api/v1/customers/{$customer->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approvalStatus', 'approved')
            ->assertJsonPath('data.approvedBy', (string) $manager->id);

        expect(AuditLog::query()->where('action', AuditAction::CustomerApproved->value)->exists())->toBeTrue();
    });

    it('rejects with a mandatory reason', function (): void {
        officerAt('Kakonko', RoleName::BranchManager);

        $customer = registeredCustomer([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'SME_MEDIUM')->value('id'),
            'dynamicFormData' => ['business_type' => 'Wholesale', 'monthly_turnover' => 4200000, 'years_in_business' => 6],
        ]);

        $this->postJson("/api/v1/customers/{$customer->id}/reject", ['reason' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/v1/customers/{$customer->id}/reject", ['reason' => 'Turnover unverifiable'])
            ->assertOk()
            ->assertJsonPath('data.approvalStatus', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'Turnover unverifiable');
    });

    it('refuses to decide a customer that is not pending', function (): void {
        officerAt('Kakonko', RoleName::BranchManager);
        $customer = registeredCustomer();

        // not_required — re-deciding would rewrite an existing decision.
        $this->postJson("/api/v1/customers/{$customer->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_CUSTOMER_STATE');
    });

    it('freezes with a reason and records an account_freezes row', function (): void {
        $officer = officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Suspected fraud'])
            ->assertOk()
            ->assertJsonPath('data.status', 'frozen');

        $freeze = AccountFreeze::query()->sole();

        expect($freeze->reason)->toBe('Suspected fraud')
            ->and($freeze->frozen_by)->toBe($officer->id)
            ->and($freeze->isOpen())->toBeTrue();
    });

    it('closes the open freeze on unfreeze rather than deleting it', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Suspected fraud'])->assertOk();
        $this->postJson("/api/v1/customers/{$customer->id}/unfreeze")->assertOk()
            ->assertJsonPath('data.status', 'active');

        // The history of why an account was frozen is what an auditor asks for.
        $freeze = AccountFreeze::query()->sole();
        expect($freeze->isOpen())->toBeFalse()
            ->and($freeze->unfrozen_at)->not->toBeNull();
    });

    it('refuses a double freeze and an unfreeze of an unfrozen account', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Suspected fraud'])->assertOk();
        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Again'])->assertStatus(409);

        $this->postJson("/api/v1/customers/{$customer->id}/unfreeze")->assertOk();
        $this->postJson("/api/v1/customers/{$customer->id}/unfreeze")->assertStatus(409);
    });

    it('suspends and reactivates, but refuses to touch a frozen account', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->patchJson("/api/v1/customers/{$customer->id}/status", ['active' => false])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->patchJson("/api/v1/customers/{$customer->id}/status", ['active' => true])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Fraud'])->assertOk();

        // A freeze needs an explicit unfreeze — it is not a status toggle.
        $this->patchJson("/api/v1/customers/{$customer->id}/status", ['active' => true])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_CUSTOMER_STATE');

        expect($customer->refresh()->status)->toBe(CustomerStatus::Frozen);
    });
});
