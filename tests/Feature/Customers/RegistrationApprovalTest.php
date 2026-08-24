<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\KycStatus;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Loan;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/**
 * The manager-approval stage of registration.
 *
 * WHAT THIS CLOSES. `approval_status` used to be decided by the customer's
 * CATEGORY: a category with `requires_extra_approval` registered `pending`,
 * every other category registered `not_required` — and `not_required` passed
 * the loan gate. So an ordinary customer became able to borrow the moment
 * their face scan passed, with no human having looked at the file. Approval is
 * now required of everybody, and eligibility is a positive condition: the
 * customer may borrow because a manager said so, not because nobody objected.
 *
 * The chain these tests walk, end to end:
 *
 *     registered → KYC complete → face verified
 *                → REGISTRATION APPROVED → loan eligible
 *
 * Nothing here touches the loan approval chain. That is a different workflow
 * with different permissions, and `LoanApprovalWorkflowTest` still owns it.
 */
beforeEach(function (): void {
    seedLoanFoundation();
});

/** A finished registration sitting in the approval queue, raised by an officer. */
function awaitingApproval(array $overrides = []): Customer
{
    officerAt('Kakonko', RoleName::LoanOfficer);

    /* `pendingRegistration`, not `registeredCustomer` — the latter approves the
       file on the way out, which is exactly the step under test here. */
    return pendingRegistration($overrides);
}

/* -------------------------------------------------------------------------
 | 1–4. What makes a customer loan eligible
 |------------------------------------------------------------------------- */

describe('the eligibility chain', function (): void {
    it('refuses a loan while KYC is incomplete', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        expect($customer->kyc_status)->toBe(KycStatus::Incomplete)
            ->and($customer->isLoanEligible())->toBeFalse();

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'KYC_INCOMPLETE');
    });

    /*
     * The regression this whole change exists to prevent. Before it, this
     * customer WAS eligible — KYC complete was the entire gate.
     */
    it('refuses a loan when KYC is complete but the registration is not approved', function (): void {
        $customer = awaitingApproval();

        expect($customer->kyc_status)->toBe(KycStatus::Completed)
            ->and($customer->approval_status)->toBe(CustomerApprovalStatus::Pending)
            ->and($customer->isLoanEligible())->toBeFalse();

        $this->postJson('/api/v1/loans', loanPayload($customer))
            ->assertStatus(422)
            /* `CUSTOMER_PENDING_APPROVAL` has no entry in the §15.2 error-code
               vocabulary, so it surfaces under the generic code with the full
               violation list in `errors.eligibility` — the documented
               behaviour of LoanNotEligibleException for unmapped codes. */
            ->assertJsonPath('error_code', 'LOAN_NOT_ELIGIBLE')
            ->assertJsonPath('errors.eligibility.0', 'Customer registration is still awaiting approval by a manager.');

        expect(Loan::query()->count())->toBe(0);
    });

    it('reports the customer as awaiting registration approval, not as eligible', function (): void {
        $customer = awaitingApproval();

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertOk()
            // KYC itself IS complete — the two are now separate facts.
            ->assertJsonPath('data.isComplete', true)
            ->assertJsonPath('data.isLoanEligible', false)
            ->assertJsonPath('data.progress.stage', 'awaiting_registration_approval')
            ->assertJsonPath('data.progress.label', 'Awaiting registration approval')
            ->assertJsonPath(
                'data.progress.nextAction',
                'Registration is complete. A Branch Manager must approve it before this customer can borrow.',
            );
    });

    it('makes the customer eligible once the manager approves', function (): void {
        $customer = awaitingApproval();

        $manager = officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();

        $customer->refresh();

        expect($customer->approval_status)->toBe(CustomerApprovalStatus::Approved)
            ->and($customer->approved_by)->toBe($manager->getKey())
            ->and($customer->approved_at)->not->toBeNull()
            ->and($customer->isLoanEligible())->toBeTrue();

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.progress.stage', 'loan_eligible');
    });
});

/* -------------------------------------------------------------------------
 | 5–6, 11. Who may decide
 |------------------------------------------------------------------------- */

describe('who approves', function (): void {
    it('does not let a Loan Officer approve a registration at all', function (): void {
        $customer = awaitingApproval();

        // Still signed in as the officer who registered them.
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertForbidden();

        expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::Pending);
    });

    /*
     * Separation of duties, mirroring the loan chain's own guard. Holding
     * `customers.approve` says you MAY approve registrations; it does not say
     * you may approve the one you created.
     */
    it('does not let an approver approve a customer they registered themselves', function (): void {
        $manager = officerAt('Kakonko', RoleName::BranchManager);
        $customer = pendingRegistration();

        expect($customer->created_by)->toBe($manager->getKey());

        $this->postJson("/api/v1/customers/{$customer->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_CUSTOMER_STATE')
            ->assertJsonPath(
                'message',
                'You registered this customer, so you cannot approve their registration. Another approver must decide.',
            );

        expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::Pending);
    });

    it('lets a Branch Manager approve a registration raised by someone else', function (): void {
        $customer = awaitingApproval();

        $manager = officerAt('Kakonko', RoleName::BranchManager);

        expect($manager->hasPermission(PermissionName::CustomersApprove))->toBeTrue();

        $this->postJson("/api/v1/customers/{$customer->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approvalStatus', 'approved');
    });

    it('refuses to approve a registration that is not finished', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/customers/{$customer->id}/approve")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'INVALID_CUSTOMER_STATE');

        expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::Pending);
    });

    it('keeps the approval queue inside the manager’s branch scope', function (): void {
        awaitingApproval();

        // A manager at another branch entirely.
        officerAt('Lindi', RoleName::BranchManager);

        expect($this->getJson('/api/v1/customers/pending-approval')->json('data'))->toHaveCount(0);
    });

    it('lists the branch’s own queue with what each file still needs', function (): void {
        $customer = awaitingApproval();

        officerAt('Kakonko', RoleName::BranchManager);

        $row = collect($this->getJson('/api/v1/customers/pending-approval')->assertOk()->json('data'))
            ->firstWhere('id', (string) $customer->getKey());

        expect($row)->not->toBeNull()
            ->and($row['kycStatus'])->toBe('completed')
            ->and($row['faceVerified'])->toBeTrue()
            // Empty is what makes the Approve button offerable.
            ->and($row['outstanding'])->toBe([])
            ->and($row['registeredByName'])->not->toBeNull();
    });
});

/* -------------------------------------------------------------------------
 | 7–9. What the loan module may see
 |------------------------------------------------------------------------- */

describe('the loan applicant selector', function (): void {
    it('offers an approved customer', function (): void {
        $customer = awaitingApproval();
        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();

        officerAt('Kakonko', RoleName::LoanOfficer);

        $ids = collect($this->getJson('/api/v1/customers?loan_eligible=1')->assertOk()->json('data'))
            ->pluck('id');

        expect($ids)->toContain((string) $customer->getKey());
    });

    it('hides a customer still awaiting approval', function (): void {
        $customer = awaitingApproval();

        $ids = collect($this->getJson('/api/v1/customers?loan_eligible=1')->json('data'))->pluck('id');

        expect($ids)->not->toContain((string) $customer->getKey());
    });

    it('hides a rejected customer', function (): void {
        $customer = awaitingApproval();

        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/reject", ['reason' => 'Address could not be confirmed.'])
            ->assertOk();

        officerAt('Kakonko', RoleName::LoanOfficer);

        $ids = collect($this->getJson('/api/v1/customers?loan_eligible=1')->json('data'))->pluck('id');

        expect($ids)->not->toContain((string) $customer->getKey());

        $this->postJson('/api/v1/loans', loanPayload($customer->refresh()))
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'LOAN_NOT_ELIGIBLE')
            ->assertJsonPath('errors.eligibility.0', 'Customer registration was rejected.');
    });

    it('hides a customer whose face scan has not passed', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $payload = registrationPayload();
        unset($payload['faceVerifiedAt']);
        $this->postJson('/api/v1/customers', $payload)->assertCreated();
        $customer = Customer::query()->latest('id')->firstOrFail();

        $ids = collect($this->getJson('/api/v1/customers?loan_eligible=1')->json('data'))->pluck('id');

        expect($ids)->not->toContain((string) $customer->getKey());
    });
});

/* -------------------------------------------------------------------------
 | Return for correction
 |------------------------------------------------------------------------- */

describe('returning a registration', function (): void {
    it('records the reason and lets the officer correct and re-submit', function (): void {
        $officer = officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = pendingRegistration();

        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/reject", ['reason' => 'Ward is wrong.'])
            ->assertOk()
            ->assertJsonPath('data.approvalStatus', 'rejected')
            ->assertJsonPath('data.rejectionReason', 'Ward is wrong.');

        // The officer sees why, in the words the manager used.
        Sanctum::actingAs($officer, ['*']);

        $this->getJson("/api/v1/customers/{$customer->id}/kyc-status")
            ->assertJsonPath('data.progress.stage', 'registration_rejected')
            ->assertJsonPath('data.progress.nextAction', 'Returned: Ward is wrong. Correct it and re-submit for approval.');

        $this->postJson("/api/v1/customers/{$customer->id}/resubmit")
            ->assertOk()
            ->assertJsonPath('data.approvalStatus', 'pending')
            // Cleared: it described a record that has since been corrected.
            ->assertJsonPath('data.rejectionReason', null);

        expect($customer->refresh()->approval_status)->toBe(CustomerApprovalStatus::Pending);
    });

    it('will not re-submit a registration that was never returned', function (): void {
        $customer = awaitingApproval();

        $this->postJson("/api/v1/customers/{$customer->id}/resubmit")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This registration has not been returned for correction.');
    });

    it('will not let the same decision be made twice', function (): void {
        $customer = awaitingApproval();

        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertStatus(409);
    });
});

/* -------------------------------------------------------------------------
 | 10. Audit
 |------------------------------------------------------------------------- */

describe('audit', function (): void {
    it('records who approved a registration and when', function (): void {
        $customer = awaitingApproval();
        $manager = officerAt('Kakonko', RoleName::BranchManager);

        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::CustomerApproved)
            ->where('auditable_id', (string) $customer->getKey())
            ->latest('id')
            ->first();

        expect($entry)->not->toBeNull()
            ->and($entry->user_id)->toBe($manager->getKey())
            ->and($entry->before_json['approval_status'])->toBe('pending')
            ->and($entry->after_json['approval_status'])->toBe('approved');
    });

    it('records the reason a registration was returned, and the re-submission', function (): void {
        $officer = officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = pendingRegistration();

        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/reject", ['reason' => 'Photo unclear.'])->assertOk();

        Sanctum::actingAs($officer, ['*']);
        $this->postJson("/api/v1/customers/{$customer->id}/resubmit")->assertOk();

        $rejected = AuditLog::query()->where('action', AuditAction::CustomerRejected)
            ->where('auditable_id', (string) $customer->getKey())->latest('id')->first();

        $resubmitted = AuditLog::query()->where('action', AuditAction::CustomerRegistrationResubmitted)
            ->where('auditable_id', (string) $customer->getKey())->latest('id')->first();

        expect($rejected?->after_json['rejection_reason'])->toBe('Photo unclear.')
            ->and($resubmitted)->not->toBeNull()
            ->and($resubmitted->user_id)->toBe($officer->getKey())
            // The reason survives in the trail even though the column is cleared.
            ->and($resubmitted->before_json['rejection_reason'])->toBe('Photo unclear.');
    });
});

/* -------------------------------------------------------------------------
 | 12. The whole journey, and the loan chain it feeds
 |------------------------------------------------------------------------- */

it('carries a customer from registration through approval to an accepted loan', function (): void {
    Storage::fake('kyc');

    /* ------------------------------------------------------ 1. registration */
    $officer = officerAt('Kakonko', RoleName::LoanOfficer);

    $payload = registrationPayload();
    unset($payload['faceVerifiedAt']);
    $this->postJson('/api/v1/customers', $payload)->assertCreated();
    $customer = Customer::query()->latest('id')->firstOrFail();

    /* --------------------------------------- 2. face scan, by somebody else */
    /* A second officer, not the one who registered them and not the manager who
       will approve — three different people, which is the point of the chain. */
    $verifier = User::factory()->role(RoleName::LoanOfficer)->create(['branch_id' => $customer->branch_id]);
    Sanctum::actingAs($verifier, ['*']);
    $this->post("/api/v1/customers/{$customer->id}/face-verify", faceScanPayload())->assertOk();

    // KYC complete — and still not able to borrow. That is the new rule.
    expect($customer->refresh()->kyc_status)->toBe(KycStatus::Completed)
        ->and($customer->isLoanEligible())->toBeFalse();

    /* --------------------------------------------- 3. the manager's decision */
    $manager = officerAt('Kakonko', RoleName::BranchManager);

    $queue = collect($this->getJson('/api/v1/customers/pending-approval')->json('data'));
    expect($queue->pluck('id'))->toContain((string) $customer->getKey());

    $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();

    expect($customer->refresh()->isLoanEligible())->toBeTrue()
        ->and($customer->approved_by)->toBe($manager->getKey());

    /* ------------------------------------------ 4. and only now, the selector */
    Sanctum::actingAs($officer, ['*']);

    $ids = collect($this->getJson('/api/v1/customers?loan_eligible=1')->json('data'))->pluck('id');
    expect($ids)->toContain((string) $customer->getKey());

    /* ------------------------------------------------------ 5. the loan itself */
    $this->postJson('/api/v1/loans', loanPayload($customer))->assertCreated();

    $loan = Loan::query()->where('customer_id', $customer->getKey())->firstOrFail();

    /* The loan approval chain is untouched by any of this — a new application
       still enters at the branch manager stage, exactly as before. */
    expect($loan->status->value)->toBe('pending_manager_approval');
});

/* -------------------------------------------------------------------------
 | The loan application's two lookups
 |------------------------------------------------------------------------- */

describe('the guarantor import pool', function (): void {
    /*
     * `GET /guarantors` — the cross-customer list the loan application's
     * "Import Guarantors" step reads. Guarantors are stored per customer, so
     * this is the only view that answers "who has stood for anybody before".
     */
    it('lists guarantors from across the branch, naming who each stands for', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", [
            'name' => 'Neema Mushi',
            'phone' => '0755111333',
            'nidaNumber' => '19880101223344',
            'relationship' => 'sibling',
            'address' => 'Kakonko',
            'occupation' => 'Trader',
        ])->assertCreated();

        $row = collect($this->getJson('/api/v1/guarantors')->assertOk()->json('data'))
            ->firstWhere('name', 'Neema Mushi');

        expect($row)->not->toBeNull()
            ->and($row['phone'])->toBe('0755111333')
            /*
             * The provenance columns. A narrowed eager-load that omitted
             * `middle_name` made this 500 at render time — `fullName()` reads
             * it — which a live request caught and no test did. Hence this.
             */
            ->and($row['customerName'])->toBe($customer->fullName())
            ->and($row['customerNumber'])->toBe($customer->customer_number);
    });

    it('searches by name, phone and identification number', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", [
            'name' => 'Neema Mushi',
            'phone' => '0755111333',
            'nidaNumber' => '19880101223344',
            'relationship' => 'sibling',
            'address' => null,
            'occupation' => null,
        ])->assertCreated();

        foreach (['Neema', '0755111333', '19880101223344'] as $term) {
            expect(collect($this->getJson("/api/v1/guarantors?search={$term}")->json('data'))->pluck('name'))
                ->toContain('Neema Mushi');
        }

        expect($this->getJson('/api/v1/guarantors?search=nobody-by-this-name')->json('data'))
            ->toBe([]);
    });

    /* §13 does not stop applying because the record is one level down. Without
       the join through the customer this would be a way to read every branch's
       guarantor book, names and phone numbers included. */
    it('does not expose another branch’s guarantors', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", [
            'name' => 'Neema Mushi',
            'phone' => '0755111333',
            'nidaNumber' => null,
            'relationship' => 'sibling',
            'address' => null,
            'occupation' => null,
        ])->assertCreated();

        officerAt('Lindi', RoleName::LoanOfficer);

        expect(collect($this->getJson('/api/v1/guarantors')->json('data'))->pluck('name'))
            ->not->toContain('Neema Mushi');
    });
});

describe('the eligible-applicant lookup', function (): void {
    it('narrows loan_eligible by name, customer number and phone in one query', function (): void {
        $customer = awaitingApproval();
        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();

        officerAt('Kakonko', RoleName::LoanOfficer);

        foreach ([$customer->first_name, $customer->customer_number, $customer->phone] as $term) {
            expect(collect($this->getJson("/api/v1/customers?loan_eligible=1&search={$term}")->json('data'))->pluck('id'))
                ->toContain((string) $customer->getKey());
        }
    });

    /* The filter and the search compose — a pending customer stays hidden even
       when the officer types their exact name. */
    it('will not surface a pending customer however precisely it is searched', function (): void {
        $customer = awaitingApproval();

        expect($this->getJson("/api/v1/customers?loan_eligible=1&search={$customer->customer_number}")->json('data'))
            ->toBe([]);
    });
});
