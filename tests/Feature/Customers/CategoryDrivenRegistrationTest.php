<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Customers\Services\KycEvaluator;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\MasterData\ContractType;
use App\Models\MasterData\IdType;
use App\Models\MasterData\Sector;
use App\Models\MasterData\SectorCategory;

/**
 * Registration asks what the CATEGORY asks for.
 *
 * A public servant has an employing body, a cadre within it, a contract and a
 * take-home figure. A boda rider has none of those and should never be shown a
 * box for them. Both facts live in `customer_categories` — three booleans and
 * a document list — so a new category is configured by an administrator and
 * not written into a form.
 *
 * Every list these tests touch comes from the database. Nothing here asserts
 * against a literal set of sectors, cadres, contract types or ID types,
 * because none of those is the application's to define.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
});

/** The seeded TAMISEMI sector and one of its cadres. */
function aSector(): Sector
{
    return Sector::query()->firstOrFail();
}

function aSectorCategory(?Sector $sector = null): SectorCategory
{
    return SectorCategory::query()->where('sector_id', ($sector ?? aSector())->getKey())->firstOrFail();
}

function contractTypeByCode(string $code): ContractType
{
    return ContractType::query()->where('code', $code)->firstOrFail();
}

/** A payload for a category that demands sector, contract and salary. */
function publicServantPayload(array $overrides = []): array
{
    $sector = aSector();

    return registrationPayload(array_merge([
        'customerCategoryId' => CustomerCategory::query()->where('code', 'PUBLIC_SERVANT')->value('id'),
        'dynamicFormData' => [
            'employer_name' => 'Halmashauri ya Wilaya ya Kakonko',
            'check_number' => 'CHK-8891',
            'account_number' => '01J0998877665',
        ],
        'sectorId' => $sector->getKey(),
        'sectorCategoryId' => aSectorCategory($sector)->getKey(),
        'contractTypeId' => contractTypeByCode('PERMANENT')->getKey(),
        'takeHome' => 780000,
        'basicSalary' => 950000,
        'employer' => 'Halmashauri ya Wilaya ya Kakonko',
    ], $overrides));
}

/* -------------------------------------------------------------------------
 | The master data itself
 |------------------------------------------------------------------------- */

describe('master data', function (): void {
    it('serves the new lists from the database, not from code', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        foreach (['id-types', 'contract-types', 'sectors'] as $list) {
            $rows = $this->getJson("/api/v1/master-data/{$list}?active=1")->assertOk()->json('data');

            expect($rows)->not->toBeEmpty();
            /* Shape, not contents: what is IN the list is the institution's
               to decide and may be edited the day after this runs. */
            expect($rows[0])->toHaveKeys(['id', 'code', 'name', 'isActive']);
        }
    });

    it('filters sector categories by their sector', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $sector = aSector();

        $mine = $this->getJson("/api/v1/master-data/sector-categories?sector_id={$sector->id}")
            ->assertOk()->json('data');

        expect($mine)->not->toBeEmpty();

        /* A second sector with its own cadre proves the filter, without
           assuming anything about what the first one contains. */
        $other = Sector::query()->create(['code' => 'TEST_SECTOR', 'name' => 'Test Sector', 'is_active' => true]);
        SectorCategory::query()->create([
            'sector_id' => $other->getKey(), 'code' => 'TEST_CADRE', 'name' => 'Test Cadre', 'is_active' => true,
        ]);

        $theirs = $this->getJson("/api/v1/master-data/sector-categories?sector_id={$other->id}")
            ->assertOk()->json('data');

        expect(collect($theirs)->pluck('code')->all())->toBe(['TEST_CADRE'])
            ->and(collect($mine)->pluck('code')->all())->not->toContain('TEST_CADRE');
    });

    it('publishes which blocks each category needs', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $data = collect($this->getJson('/api/v1/customer-categories')->assertOk()->json('data'));

        $servant = $data->firstWhere('code', 'PUBLIC_SERVANT');
        $boda = $data->firstWhere('code', 'BODA');

        expect($servant['requiresSector'])->toBeTrue()
            ->and($servant['requiresContract'])->toBeTrue()
            ->and($servant['requiresSalary'])->toBeTrue()
            ->and($boda['requiresSector'])->toBeFalse()
            ->and($boda['requiresContract'])->toBeFalse()
            ->and($boda['requiresSalary'])->toBeFalse();
    });
});

/* -------------------------------------------------------------------------
 | Identity: one type, one number
 |------------------------------------------------------------------------- */

describe('identity', function (): void {
    it('stores the chosen document type and its number', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $type = IdType::query()->where('code', 'NIDA')->firstOrFail();

        $this->postJson('/api/v1/customers', registrationPayload([
            'idTypeId' => $type->getKey(),
            'idNumber' => '19900101334455',
        ]))->assertCreated()
            ->assertJsonPath('data.idTypeId', (string) $type->getKey())
            ->assertJsonPath('data.idNumber', '19900101334455');
    });

    it('refuses a number with no type', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', registrationPayload(['idNumber' => '19900101334455']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idTypeId']);
    });

    it('refuses a type with no number', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', registrationPayload([
            'idTypeId' => IdType::query()->value('id'),
            'idNumber' => '',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idNumber']);
    });

    it('refuses an ID type that is not one the institution configured', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', registrationPayload([
            'idTypeId' => 99999,
            'idNumber' => '19900101334455',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['idTypeId']);
    });

    /* The six legacy columns still satisfy the account type's identity
       requirement, so a client written before the pair existed still works. */
    it('still accepts a registration that carries only the legacy NIDA column', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();
    });
});

/* -------------------------------------------------------------------------
 | What the category demands
 |------------------------------------------------------------------------- */

describe('category requirements', function (): void {
    it('registers a public servant with sector, cadre, contract and take-home', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $id = $this->postJson('/api/v1/customers', publicServantPayload())
            ->assertCreated()->json('data.id');

        $customer = Customer::query()->findOrFail($id);

        expect($customer->sector_id)->toBe(aSector()->getKey())
            ->and($customer->sector_category_id)->toBe(aSectorCategory()->getKey())
            ->and($customer->contract_type_id)->toBe(contractTypeByCode('PERMANENT')->getKey())
            ->and($customer->take_home)->toBe(780000);
    });

    it('demands a sector and a cadre for a category that requires them', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', publicServantPayload([
            'sectorId' => null,
            'sectorCategoryId' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sectorId', 'sectorCategoryId']);
    });

    it('refuses a cadre that belongs to a different sector', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $other = Sector::query()->create(['code' => 'OTHER_SECTOR', 'name' => 'Other Sector', 'is_active' => true]);
        $foreign = SectorCategory::query()->create([
            'sector_id' => $other->getKey(), 'code' => 'FOREIGN', 'name' => 'Foreign Cadre', 'is_active' => true,
        ]);

        $this->postJson('/api/v1/customers', publicServantPayload([
            'sectorCategoryId' => $foreign->getKey(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sectorCategoryId']);
    });

    it('demands a take-home figure for a category that requires salary', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', publicServantPayload([
            'takeHome' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['takeHome']);
    });

    /* A boda rider has no employer, no contract and no payslip. The same
       payload that fails above must succeed here without any of them. */
    it('asks a boda rider for none of it', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', registrationPayload())->assertCreated();

        $customer = Customer::query()->latest('id')->firstOrFail();

        expect($customer->sector_id)->toBeNull()
            ->and($customer->contract_type_id)->toBeNull();
    });
});

/* -------------------------------------------------------------------------
 | Permanent vs Temporary
 |------------------------------------------------------------------------- */

describe('contract terms', function (): void {
    it('requires an expiry date for a temporary contract', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', publicServantPayload([
            'contractTypeId' => contractTypeByCode('TEMPORARY')->getKey(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contractExpiryDate']);
    });

    it('accepts a temporary contract that carries its expiry', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $id = $this->postJson('/api/v1/customers', publicServantPayload([
            'contractTypeId' => contractTypeByCode('TEMPORARY')->getKey(),
            'contractExpiryDate' => '2028-06-30',
        ]))->assertCreated()->json('data.id');

        expect(Customer::query()->findOrFail($id)->contract_expiry_date?->toDateString())->toBe('2028-06-30');
    });

    /* A permanent contract with an end date is a contradiction somebody would
       later have to interpret. */
    it('refuses an expiry date on a permanent contract', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', publicServantPayload([
            'contractTypeId' => contractTypeByCode('PERMANENT')->getKey(),
            'contractExpiryDate' => '2028-06-30',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contractExpiryDate']);
    });

    it('matches on the code, so renaming the contract type does not break the rule', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $temporary = contractTypeByCode('TEMPORARY');
        $temporary->update(['name' => 'Muda Maalum']);

        $this->postJson('/api/v1/customers', publicServantPayload([
            'contractTypeId' => $temporary->getKey(),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['contractExpiryDate']);
    });
});

/* -------------------------------------------------------------------------
 | Category documents — present, and deliberately not blocking
 |------------------------------------------------------------------------- */

describe('category documents', function (): void {
    it('reports the category document list without blocking KYC', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $customer = registeredCustomer();
        $requirement = collect(app(KycEvaluator::class)->requirements($customer))
            ->firstWhere('key', 'categoryDocuments');

        expect($requirement)->not->toBeNull()
            ->and($requirement->satisfied)->toBeFalse()
            /* False until the institution turns it on — see the
               2026_08_30_000005 migration. */
            ->and($requirement->required)->toBeFalse()
            ->and($requirement->detail)->toContain('Missing:');
    });

    /* The whole point of the switch: no existing customer loses eligibility
       when the requirement lands, and every one of them would if it blocked. */
    it('leaves a customer with no documents loan-eligible', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $customer = registeredCustomer();

        expect($customer->documents()->count())->toBe(0)
            ->and($customer->fresh()->isLoanEligible())->toBeTrue();
    });

    it('blocks once the account type asks for category documents', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $customer = registeredCustomer();

        /* Turned on the way an administrator would — one column, no code.
           Written against the row the resolver will read, which is the account
           type's own profile when it has one and the default row otherwise. */
        App\Models\AccountTypeRequirement::query()
            ->where('account_type_id', $customer->account_type_id)
            ->orWhereNull('account_type_id')
            ->update(['requires_category_documents' => true]);

        $requirement = collect(app(KycEvaluator::class)->requirements($customer->fresh()))
            ->firstWhere('key', 'categoryDocuments');

        expect($requirement->required)->toBeTrue()
            ->and($requirement->outstanding())->toBeTrue();
    });
});

/* -------------------------------------------------------------------------
 | The cutoff — existing customers keep what they have
 |------------------------------------------------------------------------- */

describe('the document enforcement cutoff', function (): void {
    /** Turns the switch on, optionally from a date. */
    function enforceDocumentsFrom(?string $date): void
    {
        App\Models\AccountTypeRequirement::query()->update([
            'requires_category_documents' => true,
            'category_documents_enforced_from' => $date,
        ]);
    }

    function documentsRequirement(Customer $customer): App\Domain\Customers\DTOs\KycRequirement
    {
        return collect(app(KycEvaluator::class)->requirements($customer->fresh()))
            ->firstWhere('key', 'categoryDocuments');
    }

    /*
     * The whole point of the date. `customer_documents` holds nothing, so
     * without a cutoff switching this on takes the book's eligibility away in
     * one statement.
     */
    it('leaves a customer registered before the cutoff eligible', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        /* Their real registration date, moved back — no id list anywhere. */
        $customer->forceFill(['created_at' => now()->subMonths(3)])->save();

        enforceDocumentsFrom(now()->toDateString());

        expect($customer->documents()->count())->toBe(0)
            ->and(documentsRequirement($customer)->required)->toBeFalse()
            ->and($customer->fresh()->isLoanEligible())->toBeTrue();
    });

    it('requires documents of a customer registered on or after the cutoff', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        // Registered today; the cutoff is today. On the day itself, it applies.
        enforceDocumentsFrom(now()->toDateString());

        expect(documentsRequirement($customer)->required)->toBeTrue()
            ->and(documentsRequirement($customer)->outstanding())->toBeTrue();

        /*
         * `isLoanEligible()` reads the STORED `kyc_status`, so the switch does
         * not reach the loan gate until something re-evaluates the customer.
         * That is a second layer of safety — turning the flag on cannot make
         * the book ineligible on its own — and it is why this calls refresh()
         * explicitly rather than expecting the flag to act at a distance.
         */
        app(KycEvaluator::class)->refresh($customer);

        expect($customer->fresh()->isLoanEligible())->toBeFalse();
    });

    /*
     * Stated as its own test because it is the operational fact somebody will
     * need on the day they flip the switch: an existing customer's stored
     * status does not move until their KYC is recomputed. Enforcement is lazy,
     * not retroactive.
     */
    it('does not change a stored kyc status until the customer is re-evaluated', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        enforceDocumentsFrom(null);

        // Still eligible: nothing has recomputed them.
        expect($customer->fresh()->isLoanEligible())->toBeTrue();

        app(KycEvaluator::class)->refresh($customer);

        expect($customer->fresh()->isLoanEligible())->toBeFalse();
    });

    it('applies to everyone when no cutoff is set', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();
        $customer->forceFill(['created_at' => now()->subYears(2)])->save();

        enforceDocumentsFrom(null);

        expect(documentsRequirement($customer)->required)->toBeTrue();
    });

    it('blocks nobody while the flag is off, whatever the cutoff says', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        App\Models\AccountTypeRequirement::query()->update([
            'requires_category_documents' => false,
            'category_documents_enforced_from' => now()->subYear()->toDateString(),
        ]);

        expect(documentsRequirement($customer)->required)->toBeFalse()
            ->and($customer->fresh()->isLoanEligible())->toBeTrue();
    });

    /* The requirement clears the moment the files are on record — the cutoff
       is a start date, not a permanent exemption for the customer. */
    it('is satisfied once the category documents are uploaded', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        enforceDocumentsFrom(now()->toDateString());
        expect(documentsRequirement($customer)->outstanding())->toBeTrue();

        foreach ($customer->category->required_documents as $code) {
            $this->post("/api/v1/customers/{$customer->id}/documents", [
                'documentType' => $code,
                'file' => Illuminate\Http\UploadedFile::fake()->create("{$code}.pdf", 40, 'application/pdf'),
            ])->assertCreated();
        }

        app(KycEvaluator::class)->refresh($customer);

        expect(documentsRequirement($customer)->satisfied)->toBeTrue()
            ->and($customer->fresh()->isLoanEligible())->toBeTrue();
    });

    it('ships with the switch off and no cutoff', function (): void {
        /* A seeded install must not block anybody. If this ever fails, an
           install is enforcing documents nobody was asked for. */
        foreach (App\Models\AccountTypeRequirement::query()->get() as $profile) {
            expect($profile->requires_category_documents)->toBeFalse()
                ->and($profile->category_documents_enforced_from)->toBeNull();
        }
    });
});

/* -------------------------------------------------------------------------
 | A private employer is not a government sector
 |------------------------------------------------------------------------- */

describe('private employers', function (): void {
    it('keeps employers in their own list, separate from sectors', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/master-data/employers')->assertOk();

        /* Nothing is seeded into it: which companies a branch lends against is
           the institution's to decide, not the migration's to guess. */
        expect(App\Models\MasterData\Employer::query()->count())->toBe(0);
    });

    it('asks a private-sector customer for an employer and not a sector', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $data = collect($this->getJson('/api/v1/customer-categories')->assertOk()->json('data'));
        $private = $data->firstWhere('code', 'PRIVATE_SECTOR');
        $servant = $data->firstWhere('code', 'PUBLIC_SERVANT');

        expect($private['requiresEmployer'])->toBeTrue()
            ->and($private['requiresSector'])->toBeFalse()
            /* And the reverse for a public servant, who serves a ministry. */
            ->and($servant['requiresSector'])->toBeTrue()
            ->and($servant['requiresEmployer'])->toBeFalse();
    });

    it('refuses a private-sector registration with no employer', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/customers', registrationPayload([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'PRIVATE_SECTOR')->value('id'),
            'dynamicFormData' => [
                'employer_name' => 'Kagera Sugar',
                'employment_start_date' => '2020-01-15',
                'job_title' => 'Machine Operator',
            ],
            'employerId' => null,
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['employerId']);
    });

    it('registers a private-sector customer against an employer the admin added', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        /* Created here rather than seeded — the test needs an employer to
           exist and the application does not ship any. */
        $employer = App\Models\MasterData\Employer::query()->create([
            'code' => 'TEST_EMPLOYER', 'name' => 'Test Employer Ltd', 'is_active' => true,
        ]);

        $id = $this->postJson('/api/v1/customers', registrationPayload([
            'customerCategoryId' => CustomerCategory::query()->where('code', 'PRIVATE_SECTOR')->value('id'),
            'dynamicFormData' => [
                'employer_name' => 'Test Employer Ltd',
                'employment_start_date' => '2020-01-15',
                'job_title' => 'Machine Operator',
            ],
            'employerId' => $employer->getKey(),
        ]))->assertCreated()->json('data.id');

        $customer = Customer::query()->findOrFail($id);

        expect($customer->employer_id)->toBe($employer->getKey())
            ->and($customer->sector_id)->toBeNull();
    });
});
