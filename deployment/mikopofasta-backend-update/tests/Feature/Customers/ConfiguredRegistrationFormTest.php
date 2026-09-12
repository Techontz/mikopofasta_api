<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\MasterData\ContractType;
use App\Models\MasterData\MaritalStatusOption;
use App\Models\MasterData\Sector;
use App\Models\MasterData\SectorCategory;

/**
 * THE ACCEPTANCE TEST FOR THE WHOLE FEATURE, stated as one question:
 *
 *   Can an administrator create an entirely NEW customer type, configure the
 *   questions it asks and the documents it demands, and immediately register
 *   somebody under it — WITHOUT anybody changing a line of source?
 *
 * Every test in this file is written to be honest about that. Nothing here
 * names WATUMISHI, WAJASIRIAMALI or any other customer type an institution
 * might serve; the type is created inside the test, through the same public
 * endpoint an administrator uses, with a name that means nothing. If the
 * implementation had a hardcoded branch for a known code, these tests would
 * still pass for that code and fail here — which is the point.
 *
 * The five things a configured form has to be able to say are each exercised:
 * what is asked, how, from which list, what depends on what, and what makes a
 * field mandatory.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/**
 * A customer type whose registration form is configured entirely through the
 * API. Deliberately meaningless labels — the mechanism is the subject.
 */
function configuredType(array $overrides = []): CustomerCategory
{
    officerAt('Kakonko', RoleName::SuperAdmin);

    $payload = array_merge([
        'name' => 'Configured '.fake()->unique()->numerify('####'),
        'formTitle' => 'THE CONFIGURED SECTION',
        'requiredDocuments' => ['national_id'],
        'optionalDocuments' => ['bank_statement'],
        'dynamicFormSchema' => [
            /* A list-backed select bound to a real customer column. */
            ['key' => 'marital_status_id', 'label' => 'Marital Status', 'type' => 'select', 'required' => true, 'dataSource' => 'marital-statuses', 'storesIn' => 'maritalStatusId'],
            /* A cascade: the parent, then the child filtered by it. */
            ['key' => 'sector_id', 'label' => 'Sector', 'type' => 'select', 'required' => true, 'dataSource' => 'sectors', 'storesIn' => 'sectorId'],
            ['key' => 'sector_category_id', 'label' => 'Cadre', 'type' => 'select', 'required' => true, 'dataSource' => 'sector-categories', 'dependsOn' => 'sector_id', 'storesIn' => 'sectorCategoryId'],
            /* A conditional: the expiry only a temporary contract demands. */
            ['key' => 'contract_type_id', 'label' => 'Contract', 'type' => 'select', 'required' => true, 'dataSource' => 'contract-types', 'storesIn' => 'contractTypeId'],
            ['key' => 'contract_expiry_date', 'label' => 'Contract Ends', 'type' => 'date', 'required' => false, 'dependsOn' => 'contract_type_id', 'requiredWhen' => ['field' => 'contract_type_id', 'equals' => ['TEMPORARY']], 'storesIn' => 'contractExpiryDate'],
            /* Free text, and money, both bound to real columns. */
            ['key' => 'occupation', 'label' => 'Occupation', 'type' => 'text', 'required' => true, 'placeholder' => 'Type it in', 'storesIn' => 'occupation'],
            ['key' => 'take_home', 'label' => 'Take Home', 'type' => 'currency', 'required' => true, 'storesIn' => 'takeHome'],
            /* And one question with no column of its own, which is what the
               JSON store exists for. */
            ['key' => 'shoe_size', 'label' => 'Shoe Size', 'type' => 'number', 'required' => false],
        ],
    ], $overrides);

    $id = test()->postJson('/api/v1/customer-categories', $payload)->assertCreated()->json('data.id');

    return CustomerCategory::query()->findOrFail($id);
}

/** A registration answering everything the type above asks. */
function configuredPayload(CustomerCategory $type, array $overrides = []): array
{
    $sector = Sector::query()->firstOrFail();

    return registrationPayload(array_merge([
        'customerCategoryId' => $type->getKey(),
        'maritalStatusId' => MaritalStatusOption::query()->value('id'),
        'sectorId' => $sector->getKey(),
        'sectorCategoryId' => SectorCategory::query()->where('sector_id', $sector->getKey())->value('id'),
        'contractTypeId' => ContractType::query()->where('code', 'PERMANENT')->value('id'),
        'occupation' => 'Fundi wa viatu',
        'takeHome' => 640000,
        'dynamicFormData' => ['shoe_size' => 43],
    ], $overrides));
}

/* -------------------------------------------------------------------------
 | The configuration itself
 |------------------------------------------------------------------------- */

it('stores and serves a registration form configured through the API', function (): void {
    $type = configuredType();

    officerAt('Kakonko', RoleName::LoanOfficer);

    $served = collect(test()->getJson('/api/v1/customer-categories')->assertOk()->json('data'))
        ->firstWhere('id', (string) $type->getKey());

    expect($served['formTitle'])->toBe('THE CONFIGURED SECTION')
        ->and($served['requiredDocuments'])->toBe(['national_id'])
        ->and($served['optionalDocuments'])->toBe(['bank_statement'])
        ->and($served['dynamicFormSchema'])->toHaveCount(8);

    /* The parts that make a field more than a label survive the round trip —
       without them the wizard has a list of text boxes. */
    $cadre = collect($served['dynamicFormSchema'])->firstWhere('key', 'sector_category_id');
    expect($cadre['dataSource'])->toBe('sector-categories')
        ->and($cadre['dependsOn'])->toBe('sector_id')
        ->and($cadre['storesIn'])->toBe('sectorCategoryId');

    $expiry = collect($served['dynamicFormSchema'])->firstWhere('key', 'contract_expiry_date');
    expect($expiry['requiredWhen'])->toBe(['field' => 'contract_type_id', 'equals' => ['TEMPORARY']]);
});

it('refuses a data source the application does not manage', function (): void {
    officerAt('Kakonko', RoleName::SuperAdmin);

    test()->postJson('/api/v1/customer-categories', [
        'name' => 'Bad Source '.fake()->unique()->numerify('####'),
        'dynamicFormSchema' => [
            ['key' => 'thing', 'label' => 'Thing', 'type' => 'select', 'required' => false, 'dataSource' => 'unicorns'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('dynamicFormSchema.0.dataSource');
});

it('refuses a rule that points at a field the form does not have', function (): void {
    officerAt('Kakonko', RoleName::SuperAdmin);

    test()->postJson('/api/v1/customer-categories', [
        'name' => 'Dangling '.fake()->unique()->numerify('####'),
        'dynamicFormSchema' => [
            ['key' => 'ends_on', 'label' => 'Ends On', 'type' => 'date', 'required' => false, 'requiredWhen' => ['field' => 'nowhere', 'equals' => ['X']]],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('dynamicFormSchema.0.requiredWhen.field');
});

it('keeps an answer in the JSON store unless the form says otherwise', function (): void {
    /*
     * The default, and the reason `storesIn` is declared rather than guessed
     * from the key. `business_type` is a real customer column AND a key a
     * customer type might reasonably use for its own question; without an
     * explicit instruction the answer belongs to the type, not to the column,
     * which is where every schema written before this feature has been filing
     * it.
     */
    $type = configuredType(['dynamicFormSchema' => [
        ['key' => 'business_type', 'label' => 'Business Type', 'type' => 'text', 'required' => true],
    ], 'requiredDocuments' => []]);

    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', registrationPayload([
        'customerCategoryId' => $type->getKey(),
        'dynamicFormData' => ['business_type' => 'Duka'],
    ]))->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    expect($customer->dynamic_form_data)->toBe(['business_type' => 'Duka'])
        ->and($customer->business_type)->toBeNull();
});

it('refuses a storage location that is not on the allowlist', function (): void {
    officerAt('Kakonko', RoleName::SuperAdmin);

    /* Identity, branch and status are not a configured field's to overwrite. */
    test()->postJson('/api/v1/customer-categories', [
        'name' => 'Overreach '.fake()->unique()->numerify('####'),
        'dynamicFormSchema' => [
            ['key' => 'their_name', 'label' => 'Their Name', 'type' => 'text', 'required' => false, 'storesIn' => 'firstName'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('dynamicFormSchema.0.storesIn');
});

it('refuses two fields writing to one column', function (): void {
    officerAt('Kakonko', RoleName::SuperAdmin);

    test()->postJson('/api/v1/customer-categories', [
        'name' => 'Collide '.fake()->unique()->numerify('####'),
        'dynamicFormSchema' => [
            ['key' => 'pay', 'label' => 'Pay', 'type' => 'currency', 'required' => false, 'storesIn' => 'takeHome'],
            ['key' => 'net_pay', 'label' => 'Net Pay', 'type' => 'currency', 'required' => false, 'storesIn' => 'takeHome'],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('dynamicFormSchema.1.storesIn');
});

it('refuses two fields sharing one key', function (): void {
    officerAt('Kakonko', RoleName::SuperAdmin);

    test()->postJson('/api/v1/customer-categories', [
        'name' => 'Duplicate '.fake()->unique()->numerify('####'),
        'dynamicFormSchema' => [
            ['key' => 'same', 'label' => 'First', 'type' => 'text', 'required' => false],
            ['key' => 'same', 'label' => 'Second', 'type' => 'text', 'required' => false],
        ],
    ])->assertStatus(422)->assertJsonValidationErrors('dynamicFormSchema.1.key');
});

/* -------------------------------------------------------------------------
 | Registering against it
 |------------------------------------------------------------------------- */

it('registers a customer against a form nobody wrote code for', function (): void {
    $type = configuredType();

    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', configuredPayload($type))->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    /* The answers with a column of their own are IN that column — searchable,
       reportable, and readable by everything that already reads them. */
    expect($customer->occupation)->toBe('Fundi wa viatu')
        ->and((float) $customer->take_home)->toBe(640000.0)
        ->and($customer->sector_id)->not->toBeNull()
        ->and($customer->contract_type_id)->not->toBeNull();

    /* And the one with no column is in the JSON store, which is what it is for. */
    expect($customer->dynamic_form_data)->toBe(['shoe_size' => 43]);
});

it('enforces a required field that has a real column behind it', function (): void {
    $type = configuredType();

    officerAt('Kakonko', RoleName::LoanOfficer);

    /*
     * The rule this test exists for. `takeHome` is a first-class column and
     * arrives as a top-level key, so before the validator was given the whole
     * payload a customer type could mark it required and the server would
     * accept a registration without one — the rule was configured, displayed,
     * and enforced nowhere.
     */
    test()->postJson('/api/v1/customers', configuredPayload($type, ['takeHome' => null]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('takeHome');
});

it('enforces a required field that has no column, by its dotted path', function (): void {
    $type = configuredType(['dynamicFormSchema' => [
        ['key' => 'shoe_size', 'label' => 'Shoe Size', 'type' => 'number', 'required' => true],
    ]]);

    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', registrationPayload([
        'customerCategoryId' => $type->getKey(),
        'dynamicFormData' => [],
    ]))->assertStatus(422)->assertJsonValidationErrors('dynamicFormData.shoe_size');
});

it('demands the conditional answer only when the condition holds', function (): void {
    $type = configuredType();

    officerAt('Kakonko', RoleName::LoanOfficer);

    $temporary = ContractType::query()->where('code', 'TEMPORARY')->value('id');

    /* Temporary, no expiry: refused. */
    test()->postJson('/api/v1/customers', configuredPayload($type, [
        'nidaNumber' => '19900101234501',
        'contractTypeId' => $temporary,
        'contractExpiryDate' => null,
    ]))->assertStatus(422)->assertJsonValidationErrors('contractExpiryDate');

    /* Permanent, no expiry: accepted, because the rule does not apply. */
    test()->postJson('/api/v1/customers', configuredPayload($type, [
        'nidaNumber' => '19900101234502',
        'contractExpiryDate' => null,
    ]))->assertCreated();
});

it('matches the condition on the code, not the label', function (): void {
    $type = configuredType();

    /* The administrator renames the contract type. The rule is written against
       the code, so it must survive — this is why a condition may never be
       written against a name or an id. */
    ContractType::query()->where('code', 'TEMPORARY')->update(['name' => 'Kwa Muda']);

    officerAt('Kakonko', RoleName::LoanOfficer);

    test()->postJson('/api/v1/customers', configuredPayload($type, [
        'contractTypeId' => ContractType::query()->where('code', 'TEMPORARY')->value('id'),
        'contractExpiryDate' => null,
    ]))->assertStatus(422)->assertJsonValidationErrors('contractExpiryDate');
});

it('refuses a cascaded answer that belongs to another parent', function (): void {
    $type = configuredType();

    $otherSector = Sector::query()->create(['code' => 'OTHER_BODY', 'name' => 'Another Body', 'is_active' => true]);
    $foreignCadre = SectorCategory::query()->create([
        'sector_id' => $otherSector->getKey(),
        'code' => 'FOREIGN',
        'name' => 'Foreign Cadre',
        'is_active' => true,
    ]);

    officerAt('Kakonko', RoleName::LoanOfficer);

    /* The sector says one thing and the cadre belongs to another. Checked on
       the server, not trusted from the browser — §24. */
    test()->postJson('/api/v1/customers', configuredPayload($type, [
        'sectorCategoryId' => $foreignCadre->getKey(),
    ]))->assertStatus(422)->assertJsonValidationErrors('sectorCategoryId');
});

/* -------------------------------------------------------------------------
 | And the same for a type configured tomorrow
 |------------------------------------------------------------------------- */

it('asks a second customer type entirely different questions, with no code change', function (): void {
    $first = configuredType();

    $second = configuredType(['dynamicFormSchema' => [
        ['key' => 'business_name', 'label' => 'Business Name', 'type' => 'text', 'required' => true, 'storesIn' => 'businessName'],
        ['key' => 'land_size_acres', 'label' => 'Land Size (acres)', 'type' => 'number', 'required' => true],
    ], 'requiredDocuments' => ['business_license']]);

    officerAt('Kakonko', RoleName::LoanOfficer);

    /* What the first type demands is not asked of the second: a registration
       carrying none of the first's answers is accepted here. */
    test()->postJson('/api/v1/customers', registrationPayload([
        'customerCategoryId' => $second->getKey(),
        'businessName' => 'Duka la Mama Neema',
        'dynamicFormData' => ['land_size_acres' => 4],
    ]))->assertCreated();

    $customer = Customer::query()->latest('id')->firstOrFail();

    expect($customer->business_name)->toBe('Duka la Mama Neema')
        ->and($customer->dynamic_form_data)->toBe(['land_size_acres' => 4])
        /* And it did NOT pick up the other type's requirements. */
        ->and($customer->take_home)->toBeNull()
        ->and($first->getKey())->not->toBe($customer->customer_category_id);
});
