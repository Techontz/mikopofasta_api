<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerCategory;

describe('search', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('returns the frontend envelope with camelCase string ids', function (): void {
        officerAt('Kakonko', RoleName::Admin);
        registeredCustomer();

        $response = $this->getJson('/api/v1/customers');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [[
                    'id', 'customerNumber', 'nidaNumber', 'firstName', 'lastName', 'fullName',
                    'dob', 'gender', 'phone', 'kycStatus', 'status', 'approvalStatus',
                    'branchId', 'customerCategoryId', 'createdAt', 'deletedAt',
                ]],
                'meta' => ['pagination' => ['page', 'perPage', 'total', 'lastPage']],
            ]);

        expect($response->json('data.0.id'))->toBeString()
            ->and($response->json('data.0.branchId'))->toBeString();
    });

    it('searches by customer number, phone and name — the frontend search box', function (): void {
        officerAt('Kakonko', RoleName::Admin);
        $customer = registeredCustomer();

        foreach ([$customer->customer_number, $customer->phone, $customer->first_name, $customer->last_name] as $term) {
            expect($this->getJson('/api/v1/customers?search='.urlencode($term))->json('meta.pagination.total'))
                ->toBe(1, "search term [{$term}] should match");
        }
    });

    it('matches on the assembled full name spanning two columns', function (): void {
        officerAt('Kakonko', RoleName::Admin);
        $customer = registeredCustomer();

        // The frontend searches the joined name, so "First Last" has to match
        // even though no single column contains it.
        $fullName = $customer->first_name.' '.$customer->last_name;

        expect($this->getJson('/api/v1/customers?search='.urlencode($fullName))->json('meta.pagination.total'))
            ->toBe(1);
    });

    it('returns nothing for a term that matches no one', function (): void {
        officerAt('Kakonko', RoleName::Admin);
        registeredCustomer();

        expect($this->getJson('/api/v1/customers?search=NoSuchPerson')->json('meta.pagination.total'))->toBe(0);
    });
});

describe('filters', function (): void {
    beforeEach(function (): void {
        seedCustomerBook();
    });

    it('filters by the three faceted filters the frontend table exposes', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $all = $this->getJson('/api/v1/customers?per_page=100')->json('meta.pagination.total');
        expect($all)->toBe(24);

        $completed = $this->getJson('/api/v1/customers?kyc_status[]=completed&per_page=100')->json('meta.pagination.total');
        $incomplete = $this->getJson('/api/v1/customers?kyc_status[]=incomplete&per_page=100')->json('meta.pagination.total');
        expect($completed + $incomplete)->toBe($all)
            ->and($incomplete)->toBe(4);

        expect($this->getJson('/api/v1/customers?status[]=frozen&per_page=100')->json('meta.pagination.total'))->toBe(2)
            ->and($this->getJson('/api/v1/customers?status[]=suspended&per_page=100')->json('meta.pagination.total'))->toBe(3)
            ->and($this->getJson('/api/v1/customers?approval_status[]=pending&per_page=100')->json('meta.pagination.total'))->toBe(2);
    });

    it('accepts several values for one facet, as multi-select filters do', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $both = $this->getJson('/api/v1/customers?status[]=frozen&status[]=suspended&per_page=100')
            ->json('meta.pagination.total');

        expect($both)->toBe(5);
    });

    it('accepts a comma-separated facet as well as an array', function (): void {
        officerAt('Head Office', RoleName::Admin);

        expect($this->getJson('/api/v1/customers?status=frozen,suspended&per_page=100')->json('meta.pagination.total'))
            ->toBe(5);
    });

    it('filters by branch and category', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $kakonko = Branch::query()->where('name', 'Kakonko')->value('id');
        $boda = CustomerCategory::query()->where('code', 'BODA')->value('id');

        expect($this->getJson("/api/v1/customers?branch_id={$kakonko}&per_page=100")->json('meta.pagination.total'))->toBe(6)
            ->and($this->getJson("/api/v1/customers?customer_category_id={$boda}&per_page=100")->json('meta.pagination.total'))
            ->toBeGreaterThan(0);
    });

    it('rejects an unknown facet value', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->getJson('/api/v1/customers?status[]=exploded')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status.0']);
    });

    it('hides soft-deleted customers unless asked', function (): void {
        officerAt('Head Office', RoleName::Admin);

        Customer::query()->first()->delete();

        expect($this->getJson('/api/v1/customers?per_page=100')->json('meta.pagination.total'))->toBe(23)
            ->and($this->getJson('/api/v1/customers?include_deleted=1&per_page=100')->json('meta.pagination.total'))->toBe(24);
    });

    it('clamps per_page to the documented maximum', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->getJson('/api/v1/customers?per_page=5000')->assertStatus(422);
        expect($this->getJson('/api/v1/customers?per_page=5')->json('meta.pagination.perPage'))->toBe(5);
    });
});

describe('branch scoping', function (): void {
    beforeEach(function (): void {
        seedCustomerBook();
    });

    it('shows a branch-scoped officer only their own branch customers', function (): void {
        officerAt('Kakonko');

        $response = $this->getJson('/api/v1/customers?per_page=100');
        $kakonko = Branch::query()->where('name', 'Kakonko')->value('id');

        expect($response->json('meta.pagination.total'))->toBe(6)
            ->and(collect($response->json('data'))->pluck('branchId')->unique()->all())
            ->toBe([(string) $kakonko]);
    });

    it('gives a zone manager both branches in their zone', function (): void {
        $user = officerAt('Kakonko', RoleName::ZoneManager);
        $user->update(['zone_id' => App\Models\Zone::query()->where('name', 'West Zone')->value('id')]);

        // West Zone is Kakonko + Missenyi.
        expect($this->getJson('/api/v1/customers?per_page=100')->json('meta.pagination.total'))->toBe(12);
    });

    it('gives HQ roles every customer', function (): void {
        officerAt('Head Office', RoleName::Auditor);

        expect($this->getJson('/api/v1/customers?per_page=100')->json('meta.pagination.total'))->toBe(24);
    });

    it('refuses to show a customer outside the scope, and audits it', function (): void {
        $officer = officerAt('Kakonko');

        $lindiCustomer = Customer::query()
            ->where('branch_id', Branch::query()->where('name', 'Lindi')->value('id'))
            ->firstOrFail();

        $this->getJson("/api/v1/customers/{$lindiCustomer->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'BRANCH_SCOPE_VIOLATION');

        $log = AuditLog::query()->where('action', AuditAction::BranchScopeViolation->value)->sole();
        expect($log->after_json['identifier'])->toBe((string) $officer->id);
    });

    it('refuses to act on a customer outside the scope', function (): void {
        officerAt('Kakonko');

        $lindiCustomer = Customer::query()
            ->where('branch_id', Branch::query()->where('name', 'Lindi')->value('id'))
            ->firstOrFail();

        $this->postJson("/api/v1/customers/{$lindiCustomer->id}/freeze", ['reason' => 'Snooping'])->assertForbidden();
        /* A valid payload, like the freeze above it: the point is that the
           branch guard refuses this, and an incomplete body would be turned
           away by validation before the guard was ever reached. */
        $this->patchJson("/api/v1/customers/{$lindiCustomer->id}/status", [
            'active' => false,
            'reason' => 'Snooping',
        ])->assertForbidden();
        $this->postJson("/api/v1/customers/{$lindiCustomer->id}/notes", ['note' => 'x'])->assertForbidden();
    });
});

describe('rbac', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('denies the customer list to a role without customers.view', function (): void {
        // HR holds hr.* and reports.view only.
        officerAt('Head Office', RoleName::Hr);

        $this->getJson('/api/v1/customers')
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    });

    it('lets a read-only role read but not write', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $customer = registeredCustomer();

        // Credit Officer: customers.view but no customers.manage (§14).
        officerAt('Kakonko', RoleName::CreditOfficer);

        $this->getJson('/api/v1/customers')->assertOk();
        $this->postJson('/api/v1/customers', registrationPayload(['nidaNumber' => '19900105555555', 'phone' => '0755125555']))
            ->assertForbidden();
        $this->postJson("/api/v1/customers/{$customer->id}/notes", ['note' => 'x'])->assertForbidden();
        // A valid reason: an invalid one would return 422 before the
        // authorization check this line exists to prove.
        $this->postJson("/api/v1/customers/{$customer->id}/freeze", ['reason' => 'Attempted freeze'])->assertForbidden();
    });

    it('separates approval from management', function (): void {
        officerAt('Kakonko', RoleName::Admin);
        /* Left pending — approval is what this test is about, and
           `registeredCustomer()` would have granted it already. */
        $customer = pendingRegistration([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'SME_MEDIUM')->value('id'),
            'dynamicFormData' => ['business_type' => 'Wholesale', 'monthly_turnover' => 4200000, 'years_in_business' => 6],
        ]);

        // A Loan Officer registers customers but holds no customers.approve,
        // so cannot wave a registration through (§14).
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson("/api/v1/customers/{$customer->id}/notes", ['note' => 'Follow up'])->assertCreated();
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertForbidden();

        officerAt('Kakonko', RoleName::BranchManager);
        $this->postJson("/api/v1/customers/{$customer->id}/approve")->assertOk();
    });

    it('requires authentication throughout', function (): void {
        $this->getJson('/api/v1/customers')->assertUnauthorized();
        $this->postJson('/api/v1/customers/nida-lookup', ['nidaNumber' => '19900101234567'])->assertUnauthorized();
        $this->getJson('/api/v1/customer-categories')->assertUnauthorized();
    });
});

describe('customer categories', function (): void {
    beforeEach(function (): void {
        seedCustomerFoundation();
    });

    it('lists categories with their dynamic schema for the wizard', function (): void {
        // A Loan Officer holds no admin permission but must be able to render
        // the category step.
        officerAt('Kakonko', RoleName::LoanOfficer);

        $response = $this->getJson('/api/v1/customer-categories');

        $response->assertOk()->assertJsonCount(5, 'data');

        $boda = collect($response->json('data'))->firstWhere('code', 'BODA');

        expect($boda['riskTier'])->toBe('high')
            ->and($boda['sector'])->toBe('business')
            ->and($boda['requiredDocuments'])->toContain('driving_license')
            ->and($boda['dynamicFormSchema'])->toHaveCount(3);
    });

    it('gates category writes on admin.org_settings, not customers.manage', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $payload = [
            'name' => 'Fisherman', 'code' => 'FISH', 'riskTier' => 'medium', 'sector' => 'business',
            'requiredDocuments' => ['boat_licence'],
            'dynamicFormSchema' => [['key' => 'boat_name', 'label' => 'Boat Name', 'type' => 'text', 'required' => true]],
            'requiresExtraApproval' => false,
        ];

        $this->postJson('/api/v1/customer-categories', $payload)->assertForbidden();

        officerAt('Head Office', RoleName::Admin);
        $this->postJson('/api/v1/customer-categories', $payload)->assertCreated();
    });

    it('validates the shape of dynamic field definitions', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->postJson('/api/v1/customer-categories', [
            'name' => 'Broken', 'code' => 'BROKEN', 'riskTier' => 'low', 'sector' => 'other',
            'requiredDocuments' => [],
            'dynamicFormSchema' => [['key' => 'Not A Key', 'label' => 'X', 'type' => 'wormhole', 'required' => 'yes']],
            'requiresExtraApproval' => false,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'dynamicFormSchema.0.key',
                'dynamicFormSchema.0.type',
                'dynamicFormSchema.0.required',
            ]);
    });

    it('refuses to delete a category that has customers', function (): void {
        officerAt('Kakonko', RoleName::Admin);
        registeredCustomer();

        $boda = CustomerCategory::query()->where('code', 'BODA')->sole();

        $this->deleteJson("/api/v1/customer-categories/{$boda->id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');
    });

    it('soft-deletes an unused category', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $unused = CustomerCategory::query()->where('code', 'PRIVATE_SECTOR')->sole();

        $this->deleteJson("/api/v1/customer-categories/{$unused->id}")->assertOk();

        $this->assertSoftDeleted('customer_categories', ['id' => $unused->id]);
    });
});
