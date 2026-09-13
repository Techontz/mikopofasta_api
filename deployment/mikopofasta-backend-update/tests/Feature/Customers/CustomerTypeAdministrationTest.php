<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\Customer;
use App\Models\CustomerCategory;

/**
 * Customer Types — the broad classification the Super Administrator defines.
 *
 * The table is `customer_categories` and the payload property is
 * `customerCategoryId`; both keep their names because production customers,
 * eligibility rules and the KYC engine point at them. Everything a person reads
 * says Customer Type.
 *
 * Two rules are protected here. THE APPLICATION INVENTS NOTHING: a fresh
 * installation has no customer types at all, no seeder a production install
 * runs creates one, and the picker offers an empty list rather than a fallback.
 * And THE SUPER ADMIN OWNS THE LIST: an Admin holding `admin.org_settings` is
 * refused every write, server-side.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

function asRole(RoleName $role): void
{
    test()->actingAs(App\Models\User::factory()->role($role)->create(), 'sanctum');
}

/** Deliberately meaningless — the test is the mechanism, never a business value. */
function typePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Type '.fake()->unique()->numerify('####'),
        'code' => 'T'.fake()->unique()->numerify('####'),
        'description' => 'Created by a test.',
        'isActive' => true,
        'sortOrder' => 10,
        'riskTier' => 'medium',
        'sector' => 'business',
        'requiredDocuments' => [],
        'dynamicFormSchema' => [],
        'requiresExtraApproval' => false,
    ], $overrides);
}

describe('the application invents no customer types', function (): void {
    /*
     * The rule, stated as a test: nothing a production installation runs
     * creates a customer type. FreshInstallTest asserts the migrations create
     * none; this asserts the seeder does not either.
     */
    it('creates none from ProductionSeeder', function (): void {
        CustomerCategory::query()->forceDelete();

        $this->seed(Database\Seeders\ProductionSeeder::class);

        expect(CustomerCategory::query()->count())->toBe(0);
    });

    it('serves an empty list rather than a fallback when none are configured', function (): void {
        CustomerCategory::query()->forceDelete();

        asRole(RoleName::LoanOfficer);

        $response = $this->getJson('/api/v1/customer-categories')->assertOk();

        expect($response->json('data'))->toBe([]);
    });
});

describe('only the Super Admin manages the list', function (): void {
    it('lets the Super Admin create, edit, deactivate and reorder a type', function (): void {
        asRole(RoleName::SuperAdmin);

        $created = $this->postJson('/api/v1/customer-categories', typePayload(['name' => 'Alpha', 'code' => 'ALPHA']))
            ->assertCreated()
            ->json('data');

        expect($created['isActive'])->toBeTrue()
            ->and($created['sortOrder'])->toBe(10)
            ->and($created['description'])->toBe('Created by a test.');

        $id = $created['id'];

        $this->putJson("/api/v1/customer-categories/{$id}", typePayload([
            'name' => 'Alpha renamed', 'code' => 'ALPHA', 'isActive' => false, 'sortOrder' => 3,
        ]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Alpha renamed')
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('data.sortOrder', 3);
    });

    it('lets the Super Admin delete a type nothing is filed under', function (): void {
        asRole(RoleName::SuperAdmin);

        $id = $this->postJson('/api/v1/customer-categories', typePayload())->json('data.id');

        $this->deleteJson("/api/v1/customer-categories/{$id}")->assertOk();
        $this->assertSoftDeleted('customer_categories', ['id' => $id]);
    });

    it('refuses an Admin every write and still lets them read', function (): void {
        $type = CustomerCategory::query()->firstOrFail();

        asRole(RoleName::Admin);

        $this->postJson('/api/v1/customer-categories', typePayload())->assertForbidden();
        $this->putJson("/api/v1/customer-categories/{$type->id}", typePayload(['code' => $type->code]))->assertForbidden();
        $this->deleteJson("/api/v1/customer-categories/{$type->id}")->assertForbidden();

        $this->getJson('/api/v1/customer-categories')->assertOk();
    });

    it('refuses a Loan Officer every write and still lets them read', function (): void {
        $type = CustomerCategory::query()->firstOrFail();

        asRole(RoleName::LoanOfficer);

        $this->postJson('/api/v1/customer-categories', typePayload())->assertForbidden();
        $this->putJson("/api/v1/customer-categories/{$type->id}", typePayload(['code' => $type->code]))->assertForbidden();
        $this->deleteJson("/api/v1/customer-categories/{$type->id}")->assertForbidden();

        $this->getJson('/api/v1/customer-categories')->assertOk();
    });
});

describe('a name is the only thing asked for', function (): void {
    /*
     * The create form sends one field. Everything else — the internal code, the
     * risk tier, the document and dynamic-field lists — is the server's to
     * derive or default, because an administrator naming a customer type has no
     * opinion about any of it yet.
     */
    it('creates a customer type from a name alone', function (): void {
        asRole(RoleName::SuperAdmin);

        $created = $this->postJson('/api/v1/customer-categories', ['name' => 'Wajasiriamali'])
            ->assertCreated()
            ->json('data');

        expect($created['name'])->toBe('Wajasiriamali')
            ->and($created['code'])->toBe('WAJASIRIAMALI')
            ->and($created['isActive'])->toBeTrue()
            ->and($created['requiredDocuments'])->toBe([])
            ->and($created['dynamicFormSchema'])->toBe([])
            ->and($created['requiresExtraApproval'])->toBeFalse();
    });

    it('derives the code from the words in the name', function (): void {
        asRole(RoleName::SuperAdmin);

        $code = fn (string $name) => $this->postJson('/api/v1/customer-categories', ['name' => $name])
            ->assertCreated()->json('data.code');

        expect($code('Watumishi wa Umma'))->toBe('WATUMISHI_WA_UMMA')
            ->and($code('Waajiriwa wa Sekta Binafsi'))->toBe('WAAJIRIWA_WA_SEKTA_BINAFSI');
    });

    /* Two different names can reduce to one key. The second takes a suffix
       rather than failing in front of somebody who never asked for a code. */
    it('resolves a derived-code collision instead of failing', function (): void {
        asRole(RoleName::SuperAdmin);

        $first = $this->postJson('/api/v1/customer-categories', ['name' => 'Wakulima'])->assertCreated()->json('data.code');
        $second = $this->postJson('/api/v1/customer-categories', ['name' => 'Wakulima!'])->assertCreated()->json('data.code');

        expect($first)->toBe('WAKULIMA')->and($second)->toBe('WAKULIMA_2');
    });

    /*
     * The trap this guards. A rename sends the NAME only; reading the absent
     * keys as "empty" would wipe the documents, the dynamic fields and the risk
     * tier off a type the administrator merely wanted to spell differently.
     */
    it('changes nothing but the name when only a name is sent', function (): void {
        asRole(RoleName::SuperAdmin);

        $type = CustomerCategory::query()->firstOrFail();
        $before = $type->only('code', 'risk_tier', 'sector', 'required_documents', 'dynamic_form_schema', 'requires_extra_approval', 'is_active', 'sort_order');

        expect($before['required_documents'])->not->toBeEmpty();

        $this->putJson("/api/v1/customer-categories/{$type->id}", ['name' => 'Renamed only'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed only');

        expect($type->refresh()->only(array_keys($before)))->toBe($before);
    });

    it('still refuses a name that is already taken', function (): void {
        asRole(RoleName::SuperAdmin);

        $existing = CustomerCategory::query()->firstOrFail();

        $this->postJson('/api/v1/customer-categories', ['name' => $existing->name])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    });
});

describe('what registration is offered', function (): void {
    it('offers active types only, in the administrator\'s order', function (): void {
        asRole(RoleName::SuperAdmin);
        CustomerCategory::query()->forceDelete();

        $this->postJson('/api/v1/customer-categories', typePayload(['code' => 'ON1', 'name' => 'Second', 'sortOrder' => 20]))->assertCreated();
        $this->postJson('/api/v1/customer-categories', typePayload(['code' => 'ON2', 'name' => 'First', 'sortOrder' => 10]))->assertCreated();
        $this->postJson('/api/v1/customer-categories', typePayload(['code' => 'OFF', 'name' => 'Retired', 'isActive' => false]))->assertCreated();

        asRole(RoleName::LoanOfficer);

        $all = $this->getJson('/api/v1/customer-categories')->json('data');
        $offered = $this->getJson('/api/v1/customer-categories?activeOnly=1')->json('data');

        expect($all)->toHaveCount(3)
            ->and($offered)->toHaveCount(2)
            /* Display order, not alphabetical — the administrator decides. */
            ->and(array_column($offered, 'code'))->toBe(['ON2', 'ON1']);
    });

    /*
     * Deactivating must never touch the customers already filed under a type.
     * Their classification is history, not a live preference.
     */
    it('keeps existing customers when a type is deactivated', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();
        $typeId = $customer->customer_category_id;

        expect($typeId)->not->toBeNull();

        $type = CustomerCategory::query()->findOrFail($typeId);

        asRole(RoleName::SuperAdmin);
        $this->putJson("/api/v1/customer-categories/{$typeId}", typePayload([
            'name' => $type->name, 'code' => $type->code, 'isActive' => false,
        ]))->assertOk();

        expect($customer->refresh()->customer_category_id)->toBe($typeId)
            ->and(Customer::query()->whereNull('customer_category_id')->count())->toBe(0);
    });

    it('refuses to delete a type customers are filed under', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        asRole(RoleName::SuperAdmin);

        $this->deleteJson("/api/v1/customer-categories/{$customer->customer_category_id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        expect($customer->refresh()->customer_category_id)->not->toBeNull();
    });

    it('persists the chosen type on the customer', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $type = CustomerCategory::query()->firstOrFail();

        $id = $this->postJson('/api/v1/customers', registrationPayload(['customerCategoryId' => $type->id]))
            ->assertCreated()
            ->json('data.id');

        expect(Customer::query()->findOrFail($id)->customer_category_id)->toBe($type->id);
    });
});

describe('a save that predates the new columns', function (): void {
    /*
     * The trap: an older client sends a type save with no `isActive`. That must
     * leave the switch alone, not silently put a retired type back into the
     * registration picker.
     */
    it('leaves activation and order alone when a save does not mention them', function (): void {
        asRole(RoleName::SuperAdmin);

        $id = $this->postJson('/api/v1/customer-categories', typePayload(['code' => 'KEEP', 'isActive' => false, 'sortOrder' => 7]))
            ->json('data.id');

        $payload = typePayload(['code' => 'KEEP']);
        unset($payload['isActive'], $payload['sortOrder'], $payload['description']);

        $this->putJson("/api/v1/customer-categories/{$id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.isActive', false)
            ->assertJsonPath('data.sortOrder', 7);
    });
});
