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
use App\Models\MasterData\AccountType;
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
            /*
             * Both FALSE, and the customer is complete anyway.
             *
             * That pairing is the point. Neither external check can be
             * performed — there is no NIDA registry and no SMS gateway — so
             * neither is claimed, and neither is required. These two used to
             * assert `true` only because the test fixture stamped the
             * timestamps itself, which is the fabrication the whole design
             * refuses. See KycEvaluator.
             */
            ->assertJsonPath('data.checklist.nidaVerified', false)
            ->assertJsonPath('data.checklist.otpVerified', false)
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

    /*
     * The bank account requirement is the account type's, not a universal one.
     *
     * This test used to assert that any customer without bank details was
     * incomplete. Many microfinance customers have no bank account and settle
     * everything through a wallet, so the default profile does not ask for one
     * — but a SAVINGS account plainly does, and the checklist must follow the
     * account type rather than a fixed list. The arc is otherwise the original
     * one: missing, then supplied, then complete, with nobody setting the
     * column by hand.
     */
    it('requires a bank account only when the account type asks for one', function (): void {
        officerAt();
        $customer = registeredCustomer(['bankDetails' => null]);

        // Default profile: no bank account demanded, so nothing is outstanding.
        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.isComplete', true);

        $customer->update([
            'account_type_id' => AccountType::query()->where('code', 'SAVINGS')->value('id'),
        ]);

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.checklist.additionalDataComplete', false)
            ->assertJsonPath('data.isComplete', false);

        expect(
            collect($this->getJson("/api/v1/customers/{$customer->id}/kyc-status")->json('data.requirements'))
                ->firstWhere('key', 'bankAccount'),
        )->toMatchArray(['required' => true, 'satisfied' => false]);

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
        /* Approved already — which is what makes the reassignment visible: the
           point is that moving into a demanding category takes approval AWAY
           again, and a customer who was never approved could not show that. */
        $customer = registeredCustomer();
        expect($customer->approval_status)->toBe(CustomerApprovalStatus::Approved);

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

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
            ->assertOk();

        $customer->refresh();

        expect($customer->face_verified_at)->not->toBeNull()
            ->and($customer->photo_path)->toStartWith('customers/'.$customer->id.'/');

        Storage::disk(KycDocumentStorage::DISK)->assertExists($customer->photo_path);
    });

    it('never returns the stored path — only a signed URL', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())
            ->assertOk();

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

        $this->postJson(
            "/api/v1/customers/{$customer->id}/face-verify",
            faceScanPayload(['capture' => UploadedFile::fake()->create('malware.exe', 100)]),
        )
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

    it('refuses a document type that is not on the admin-managed list', function (): void {
        officerAt();
        $customer = registeredCustomer();

        // The free-text box that used to be here put a document filed under
        // "HJK" into this database. A category requiring `salary_slip` is not
        // satisfied by anything an officer happens to type.
        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'HJK',
            'file' => UploadedFile::fake()->create('mystery.pdf', 20, 'application/pdf'),
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['documentType']);

        expect($customer->documents()->count())->toBe(0);
    });

    it('refuses a document type that has been withdrawn', function (): void {
        officerAt();
        $customer = registeredCustomer();

        App\Models\MasterData\DocumentType::query()
            ->where('code', 'salary_slip')
            ->update(['is_active' => false]);

        // Still readable on the documents that hold it; no longer offerable.
        $this->postJson("/api/v1/customers/{$customer->id}/documents", [
            'documentType' => 'salary_slip',
            'file' => UploadedFile::fake()->create('slip.pdf', 20, 'application/pdf'),
        ])->assertStatus(422);
    });

    it('offers the document types a category actually requires', function (): void {
        officerAt();

        $codes = collect($this->getJson('/api/v1/master-data/document-types?active=1')->json('data'))
            ->pluck('code');

        // Every code the shipped categories name has a row, or the checklist
        // is asking for something no officer can file.
        $required = CustomerCategory::query()
            ->pluck('required_documents')
            ->flatten()
            ->unique();

        expect($required->diff($codes)->all())->toBe([]);
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
        /* Registered by an officer, decided by a manager. Two people, because
           the action refuses anyone approving a file they created themselves. */
        officerAt('Kakonko', RoleName::LoanOfficer);

        $customer = pendingRegistration([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'SME_MEDIUM')->value('id'),
            'dynamicFormData' => ['business_type' => 'Wholesale', 'monthly_turnover' => 4200000, 'years_in_business' => 6],
        ]);

        $manager = officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/customers/{$customer->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approvalStatus', 'approved')
            ->assertJsonPath('data.approvedBy', (string) $manager->id);

        expect(AuditLog::query()->where('action', AuditAction::CustomerApproved->value)->exists())->toBeTrue();
    });

    it('rejects with a mandatory reason', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $customer = pendingRegistration([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'SME_MEDIUM')->value('id'),
            'dynamicFormData' => ['business_type' => 'Wholesale', 'monthly_turnover' => 4200000, 'years_in_business' => 6],
        ]);

        officerAt('Kakonko', RoleName::BranchManager);

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

    it('reads the freeze history back, open and closed', function (): void {
        officerAt('Kakonko', RoleName::BranchManager);
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Suspected fraud'])->assertOk();
        $this->postJson("/api/v1/customers/{$customer->id}/unfreeze")->assertOk();
        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Court order'])->assertOk();

        $history = $this->getJson("/api/v1/customers/{$customer->id}/freezes")->assertOk()->json('data');

        // Newest first, and the closed one is still there — a customer frozen
        // three times must not look like one frozen once.
        expect($history)->toHaveCount(2)
            ->and($history[0]['reason'])->toBe('Court order')
            ->and($history[0]['unfrozenAt'])->toBeNull()
            ->and($history[1]['reason'])->toBe('Suspected fraud')
            ->and($history[1]['unfrozenAt'])->not->toBeNull()
            ->and($history[1]['unfrozenBy'])->not->toBeNull();
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

    it('refuses to change status without a reason', function (): void {
        officerAt();
        $customer = registeredCustomer();

        // Suspension stops a customer borrowing. It used to need nothing but a
        // boolean, so an account could be suspended with no recorded grounds.
        $this->patchJson("/api/v1/customers/{$customer->id}/status", ['active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        // And lifting one is a decision too.
        $this->patchJson("/api/v1/customers/{$customer->id}/status", ['active' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);

        expect($customer->refresh()->status)->toBe(CustomerStatus::Active)
            ->and($customer->status_reason)->toBeNull();
    });

    it('records the reason, remarks and operator on a suspension', function (): void {
        $officer = officerAt();
        $customer = registeredCustomer();

        $this->patchJson("/api/v1/customers/{$customer->id}/status", [
            'active' => false,
            'reason' => 'Suspected identity mismatch',
            'remarks' => 'Case REF-8891; customer notified by SMS.',
        ])
            ->assertOk()
            ->assertJsonPath('data.statusReason', 'Suspected identity mismatch')
            ->assertJsonPath('data.statusRemarks', 'Case REF-8891; customer notified by SMS.');

        $customer->refresh();

        expect($customer->status_changed_by)->toBe($officer->getKey())
            ->and($customer->status_changed_at)->not->toBeNull();
    });

    it('audits a status change with operator, branch, address and client', function (): void {
        $officer = officerAt();
        $customer = registeredCustomer();

        $this->patchJson("/api/v1/customers/{$customer->id}/status", [
            'active' => false,
            'reason' => 'Court order',
            'remarks' => 'Order 44/2026',
        ])->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::CustomerSuspended->value)
            ->latest('id')
            ->sole();

        // Exactly the record a freeze or a rejection leaves.
        expect($entry->user_id)->toBe($officer->getKey())
            ->and($entry->after_json['reason'])->toBe('Court order')
            ->and($entry->after_json['remarks'])->toBe('Order 44/2026')
            ->and($entry->after_json['operator'])->toBe($officer->name)
            ->and($entry->after_json['branch'])->not->toBeNull()
            ->and($entry->after_json['branch_id'])->toBe($customer->branch_id)
            ->and($entry->after_json['changed_at'])->not->toBeNull()
            ->and($entry->before_json['status'])->toBe('active')
            ->and($entry->ip_address)->not->toBeNull()
            ->and($entry->user_agent)->not->toBeNull()
            ->and($entry->created_at)->not->toBeNull();
    });

    it('records the reason for a reactivation too', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->patchJson("/api/v1/customers/{$customer->id}/status", [
            'active' => false, 'reason' => 'Under review',
        ])->assertOk();

        $this->patchJson("/api/v1/customers/{$customer->id}/status", [
            'active' => true, 'reason' => 'Review closed, no findings',
        ])->assertOk();

        expect($customer->refresh()->status_reason)->toBe('Review closed, no findings');

        expect(AuditLog::query()->where('action', AuditAction::CustomerReactivated->value)->sole()
            ->after_json['reason'])->toBe('Review closed, no findings');
    });

    it('suspends and reactivates, but refuses to touch a frozen account', function (): void {
        officerAt();
        $customer = registeredCustomer();

        $this->patchJson("/api/v1/customers/{$customer->id}/status", [
            'active' => false,
            'reason' => 'Repeated missed appointments',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended');

        $this->patchJson("/api/v1/customers/{$customer->id}/status", [
            'active' => true,
            'reason' => 'Customer attended and explained',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Fraud'])->assertOk();

        // A freeze needs an explicit unfreeze — it is not a status toggle.
        $this->patchJson("/api/v1/customers/{$customer->id}/status", [
            'active' => true,
            'reason' => 'Trying to toggle a frozen account',
        ])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_CUSTOMER_STATE');

        expect($customer->refresh()->status)->toBe(CustomerStatus::Frozen);
    });
});
