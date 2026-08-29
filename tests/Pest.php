<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\User;
use Database\Factories\UserFactory;
use Database\Seeders\CustomerCategorySeeder;
use Database\Seeders\GeographySeeder;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests hit the real MySQL test schema (see phpunit.xml) and are
| wrapped in a transaction by RefreshDatabase, so ledger assertions run
| against the same engine and column types as production.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    /*
     * Every feature test starts from the platform floor: permissions, roles and
     * the System account — the same three seeders `migrate:fresh --seed` runs
     * before anything else, in the same order.
     *
     * This is here rather than in each test because the System account is
     * infrastructure, not a fixture. A test that forgot it would not fail on
     * the missing account; it would fail somewhere else entirely, on a 503 from
     * whichever automated path it happened to touch. `FoundationTest`'s health
     * check found exactly that, and the answer is for the foundation to mirror
     * production rather than for each test to remember.
     *
     * `seedRbac()` short-circuits once seeded, so the explicit calls throughout
     * the suite cost one query each and are left in place: they document what a
     * test depends on, and a test that spells out its own preconditions still
     * reads correctly on its own.
     */
    ->beforeEach(fn () => seedRbac())
    ->in('Feature');

/*
 * Unit tests boot the application but never touch the database — note the
 * absence of RefreshDatabase. Eloquent models can be constructed and reasoned
 * about (PaymentAllocator takes LoanSchedule instances) without any of them
 * being saved, which is what keeps these tests fast and keeps the rules they
 * cover independent of the schema.
 */
pest()->extend(Tests\TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Roles and permissions are reference data, not fixtures: a user cannot exist
 * without a seeded role, and permission checks are meaningless without the §14
 * grants. Every test that touches identity calls this first.
 */
function seedRbac(): void
{
    /*
     * Idempotent, because it now runs from the global beforeEach AND from the
     * dozens of tests that call it explicitly. The System role is the marker
     * rather than any other: it is created by RoleSeeder and is the one thing
     * no factory or fixture ever produces, so its presence means the whole
     * platform floor is down and not merely part of it.
     */
    if (App\Models\Role::query()->where('name', RoleName::System->value)->exists()) {
        return;
    }

    test()->seed(PermissionSeeder::class);
    test()->seed(RoleSeeder::class);

    /*
     * The System account is infrastructure, not a fixture.
     *
     * Every automated path resolves it — the provider webhook, the disbursement
     * callback, the nightly advance consumption, the penalty run — and
     * SystemActor REFUSES rather than substituting a human. A test foundation
     * without it would make those paths 503, which is precisely what the
     * refusal is for.
     *
     * Seeded HERE rather than per test, so the test infrastructure mirrors
     * production: on a real installation the account exists from the moment the
     * seeders run, and no test should have to remember to create it.
     *
     * After RoleSeeder, because the account needs the `system` role.
     */
    test()->seed(Database\Seeders\SystemUserSeeder::class);
}

/**
 * Seeds the organization: geography, branches and the company profile, then the
 * hierarchy and zone scaffolding the suite needs.
 *
 * OrganizationSeeder now creates only the branches the legacy system actually
 * shows, none of which is a sub-branch and none of which belongs to a zone —
 * the legacy branch screen has never been captured, so there is nothing to copy.
 * TestOrgScaffoldSeeder adds a parent branch and two zones on top, purely so the
 * §12 hierarchy and §13 zone-scoping tests have something to assert against. See
 * that class for why it is separate.
 *
 * Implies seedRbac(), since nothing organizational is reachable without a
 * logged-in user and users need roles.
 */
function seedOrganization(): void
{
    seedRbac();
    test()->seed(GeographySeeder::class);
    test()->seed(OrganizationSeeder::class);
    test()->seed(Database\Seeders\TestOrgScaffoldSeeder::class);
}

/**
 * Seeds the organization plus the customer categories — everything a customer
 * needs to exist. Implies seedOrganization().
 *
 * The lookup lists and their requirement profiles are part of that foundation
 * now, not decoration. Which fields a registration must carry is decided by
 * `account_type_requirements`, keyed to rows in `account_types`, so a suite
 * without them can only ever exercise the default profile. (The default
 * profile row itself comes from the 2026_08_26 migration, like the document
 * types do — it is a schema invariant rather than seed data.)
 */
function seedCustomerFoundation(): void
{
    seedOrganization();
    test()->seed(Database\Seeders\MasterDataSeeder::class);
    /*
     * Sectors, cadres, ID types, contract types and the document types the
     * categories name. Named EXPLICITLY here rather than inherited from a
     * migration: the migrations create empty tables now, because which of
     * these an institution uses is its own decision. A test that needs a
     * sector says so.
     */
    test()->seed(Database\Seeders\ReferenceDataSeeder::class);
    test()->seed(Database\Seeders\AccountTypeRequirementSeeder::class);
    test()->seed(CustomerCategorySeeder::class);
}

/**
 * Seeds the full demo customer book: foundation, a registering officer, then
 * CustomerSeeder.
 *
 * The officer is not optional. CustomerSeeder stamps `created_by` on every
 * customer and bails out if no user exists, so seeding customers without one
 * silently produces an empty book — which shows up as a filter test asserting
 * against zero rows rather than as an error.
 */
function seedCustomerBook(): void
{
    seedCustomerFoundation();

    test()->seed(Database\Seeders\UserSeeder::class);
    test()->seed(Database\Seeders\CustomerSeeder::class);
}

/**
 * Creates a user holding the given role, with RBAC already seeded.
 */
function userWithRole(RoleName $role, array $attributes = []): User
{
    seedRbac();

    return User::factory()->role($role)->create($attributes);
}

/**
 * Creates a user with the given role and authenticates them for the request.
 *
 * Sanctum::actingAs is used rather than a real bearer token because the token
 * abilities would otherwise have to be restated in every test; the ability
 * scoping itself is covered explicitly in AuthenticationTest.
 */
function actingAsRole(RoleName $role, array $attributes = []): User
{
    $user = userWithRole($role, $attributes);

    Sanctum::actingAs($user, ['*']);

    return $user;
}

/**
 * The password every factory-made user has.
 */
function defaultPassword(): string
{
    return UserFactory::PASSWORD;
}

/**
 * Clears the resolved user from the auth guards.
 *
 * Laravel's test client reuses a single application container across requests,
 * so the guard caches whichever user it resolved for the previous request. Any
 * test that fires a second request with a DIFFERENT bearer token must call
 * this first — otherwise the second request silently reuses the first
 * request's identity and an assertion about token revocation proves nothing.
 *
 * Production has no equivalent: each request gets a fresh container.
 */
function forgetAuthGuards(): void
{
    app('auth')->forgetGuards();
}

/**
 * Creates a user at a named branch and authenticates them.
 *
 * Lives here rather than in one test file because several customer suites need
 * it; a helper defined in a sibling file is invisible when that file is not
 * part of the run.
 */
function officerAt(string $branchName = 'Kakonko', RoleName $role = RoleName::LoanOfficer): User
{
    $user = User::factory()->role($role)->create([
        'branch_id' => App\Models\Branch::query()->where('name', $branchName)->value('id'),
    ]);

    Sanctum::actingAs($user, ['*']);

    return $user;
}

/**
 * A complete, valid registration payload.
 *
 * Identity fields come from NidaRegistry rather than being written by hand, so
 * the fixture obeys the same rule §9 imposes on real registration: NIDA is the
 * source of truth and those values are never typed.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    $nidaNumber = $overrides['nidaNumber'] ?? '19900101234567';
    $identity = app(App\Domain\Customers\Services\NidaRegistry::class)->lookup($nidaNumber);

    $overrides = array_merge(categoryBlocksFor($overrides), $overrides);

    return array_merge([
        'nidaNumber' => $nidaNumber,
        /*
         * No `nidaVerifiedAt`, no `otpVerifiedAt`.
         *
         * The fixture used to stamp both with `now()`, which made every test
         * customer claim a registry lookup and an SMS code that never happened
         * — the exact fabrication this system refuses. Neither integration
         * exists, so the API now rejects a claimed verification outright (see
         * RegisterCustomerRequest::checkVerificationClaims), and a fixture that
         * kept sending them would be testing a path no real client can take.
         *
         * `faceVerifiedAt` stays, because a liveness scan genuinely can be
         * performed here — the scanner is real. Tests exercising the
         * awaiting-verification path unset it.
         */
        'faceVerifiedAt' => now()->toIso8601String(),

        'firstName' => $identity->firstName,
        'middleName' => $identity->middleName,
        'lastName' => $identity->lastName,
        'dob' => $identity->dob,
        'gender' => $identity->gender->value,

        'phone' => '0755123456',
        'maritalStatus' => 'married',
        'regionId' => App\Models\Region::query()->where('name', 'Kigoma')->value('id'),
        /*
         * A district, because the default account-type requirement profile
         * asks for one — region and district are the two levels our reference
         * data is authoritative about, so both gate. Ward and street are typed
         * and never required; see the 2026_08_26 migration.
         */
        'districtId' => App\Models\District::query()->where('name', 'Kakonko')->value('id'),
        'wardName' => 'Kakonko Mjini',
        'streetName' => 'Market Street',
        'residenceType' => 'owned',
        'branchId' => App\Models\Branch::query()->where('name', 'Kakonko')->value('id'),
        'customerCategoryId' => App\Models\CustomerCategory::query()->where('code', 'BODA')->value('id'),
        'dynamicFormData' => [
            'motorcycle_registration_number' => 'MC 123 ABC',
            'daily_income' => 35000,
        ],
        'bankDetails' => [
            'bankName' => 'CRDB Bank',
            'accountNumber' => '01J0123456789',
            'accountName' => 'Test Customer',
            'phoneNumber' => '0755123456',
        ],
        'guarantors' => [[
            'name' => 'Hamisi Ally',
            'phone' => '0755999111',
            'nidaNumber' => null,
            'relationship' => 'friend',
            'address' => 'Kakonko',
            'occupation' => 'Trader',
        ]],
        'nextOfKin' => [[
            'name' => 'Neema Juma',
            'relationship' => 'spouse',
            'phone' => '0755999222',
            'address' => 'Kakonko',
        ]],
    ], $overrides);
}

/**
 * Registers a customer through the API and returns the persisted model.
 */
function registeredCustomer(array $overrides = []): App\Models\Customer
{
    $customer = pendingRegistration($overrides);

    /*
     * Approved, because "a registered customer" now means one a manager has
     * cleared — and that is the state every downstream test needs.
     *
     * Registration approval became mandatory for everybody (see the
     * 2026_08_28 migration), so a freshly registered customer is `pending` and
     * cannot borrow. Roughly a hundred and seventy tests across the loan,
     * repayment and penalty suites take "there is a customer" as their
     * starting point and are not about approval at all; leaving them to fail on
     * a gate they do not exercise would say nothing true about the code.
     *
     * Written on the model rather than through the endpoint on purpose. Going
     * through the API would need a second authenticated user with
     * `customers.approve` — the self-approval guard forbids the registrant
     * deciding their own file — and every one of those tests would then be
     * quietly authenticated as somebody they did not choose.
     *
     * Use `pendingRegistration()` when the approval stage IS the subject.
     */
    $customer->forceFill([
        'approval_status' => App\Domain\Customers\Enums\CustomerApprovalStatus::Approved,
        'approved_at' => now(),
    ])->save();

    return $customer->refresh();
}

/**
 * A customer as registration actually leaves them: complete, and waiting for a
 * manager. The state `registeredCustomer()` approves away.
 *
 * @param array<string, mixed> $overrides
 */
function pendingRegistration(array $overrides = []): App\Models\Customer
{
    test()->postJson('/api/v1/customers', registrationPayload($overrides))->assertCreated();

    // firstOrFail, not sole(): a test that registers two customers would
    // otherwise trip sole()'s "multiple records" guard on the second call.
    return App\Models\Customer::query()->latest('id')->firstOrFail();
}

/**
 * A complete, passing face-scan payload for POST /face-verify.
 *
 * The endpoint requires every measurement — a capture that cannot say what it
 * measured is not a verification — so a test that only wants "a scan happened"
 * would otherwise carry thirty lines of scores. Pass overrides to make one
 * fail, or to change a single score.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function faceScanPayload(array $overrides = []): array
{
    $checks = array_merge(
        array_fill_keys(App\Http\Requests\Customers\FaceVerifyRequest::CHECKS, true),
        (array) ($overrides['checks'] ?? []),
    );

    unset($overrides['checks']);

    return array_merge([
        'capture' => Illuminate\Http\UploadedFile::fake()->image('liveness.jpg'),
        'status' => 'passed',
        'qualityScore' => 92,
        'brightnessScore' => 88,
        'blurScore' => 90,
        'distanceScore' => 95,
        'centeringScore' => 93,
        'eyesOpenScore' => 99,
        'scannerVersion' => 'mediapipe-face-landmarker@1.0.0',
        'livenessPassed' => true,
        'poseSequenceCompleted' => true,
        'captureDevice' => 'FaceTime HD Camera',
        'captureResolution' => '1280x720',
        'captureDurationMs' => 8420,
        'checks' => $checks,
    ], $overrides);
}

/**
 * Seeds everything a loan needs: the customer foundation plus the loan
 * configuration layer (formulas, cadences, products and the §2.3 eligibility
 * pivot).
 */
function seedLoanFoundation(): void
{
    seedCustomerFoundation();
    test()->seed(Database\Seeders\LoanProductSeeder::class);

    // The approval chain is configuration a loan cannot move without.
    test()->seed(Database\Seeders\LoanApprovalStageSeeder::class);
}

/**
 * A registered customer who passes every §6 gate: KYC complete, active, in the
 * Boda Boda category, with a guarantor on record.
 */
function eligibleCustomer(string $category = 'BODA'): App\Models\Customer
{
    // Each call registers a DISTINCT person: NIDA number and phone are both
    // unique columns, so a test that needs two customers would otherwise
    // collide on the fixture's defaults.
    static $sequence = 0;
    $sequence++;

    $overrides = [
        'nidaNumber' => sprintf('1990010%07d', 1234567 + $sequence * 13),
        'phone' => sprintf('07551%05d', 23456 + $sequence * 7),
    ];

    if ($category !== 'BODA') {
        $overrides['customerCategoryId'] = App\Models\CustomerCategory::query()
            ->where('code', $category)->value('id');

        $overrides['dynamicFormData'] = match ($category) {
            'PUBLIC_SERVANT' => [
                'employer_name' => 'Ministry of Health',
                'check_number' => 'CHK123456',
                'account_number' => '01J0000000001',
            ],
            default => [],
        };
    }

    return registeredCustomer($overrides);
}

/**
 * The sector, contract and salary a CATEGORY demands, filled from the database.
 *
 * `customer_categories` carries three booleans saying which of the first-class
 * employment blocks a category asks for — see the 2026_08_30 migration. A test
 * that names PUBLIC_SERVANT is asking for a salaried customer, and a salaried
 * customer has an employing body, a cadre, a contract and a take-home figure.
 * Filling them here rather than in each test means a suite that only cares
 * about loan approval does not have to know what a public servant needs, and a
 * category configured later gets the same treatment for free.
 *
 * Values come from whatever is seeded, never from literals: the point of the
 * tables is that the institution decides what is in them.
 *
 * The caller's own overrides win — a test deliberately omitting a sector to
 * prove the rule must still be able to.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function categoryBlocksFor(array $overrides): array
{
    $categoryId = $overrides['customerCategoryId'] ?? null;

    if ($categoryId === null) {
        return [];
    }

    $category = App\Models\CustomerCategory::query()->find($categoryId);

    if ($category === null) {
        return [];
    }

    $blocks = [];

    if ($category->requires_sector) {
        $sector = App\Models\MasterData\Sector::query()->first();
        $blocks['sectorId'] = $sector?->getKey();
        $blocks['sectorCategoryId'] = App\Models\MasterData\SectorCategory::query()
            ->where('sector_id', $sector?->getKey())->value('id');
    }

    if ($category->requires_employer) {
        /* Nothing ships in `employers` — which companies a branch lends
           against is the institution's decision — so the fixture creates one
           on demand rather than assuming a seeded row. */
        $blocks['employerId'] = App\Models\MasterData\Employer::query()->firstOrCreate(
            ['code' => 'FIXTURE_EMPLOYER'],
            ['name' => 'Fixture Employer Ltd', 'is_active' => true],
        )->getKey();
    }

    if ($category->requires_contract) {
        /* Permanent, so no expiry date is needed — a temporary contract would
           make every caller supply one. */
        $blocks['contractTypeId'] = App\Models\MasterData\ContractType::query()
            ->where('code', 'PERMANENT')->value('id');
    }

    if ($category->requires_salary) {
        $blocks['takeHome'] = 650000;
        $blocks['basicSalary'] = 800000;
    }

    return $blocks;
}

function bodaProduct(): App\Models\LoanProduct
{
    return App\Models\LoanProduct::query()->with('repaymentSchedules')->where('code', 'BODA_WC')->sole();
}

/**
 * A valid loan application payload for the given customer.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function loanPayload(App\Models\Customer $customer, array $overrides = []): array
{
    $product = bodaProduct();

    return array_merge([
        'customerId' => $customer->getKey(),
        'loanProductId' => $product->getKey(),
        'repaymentScheduleId' => $product->repaymentSchedules->firstWhere('code', 'WEEKLY')->getKey(),
        'principalAmount' => '500000.00',
        'tenureDays' => 90,
    ], $overrides);
}

/**
 * A loan sitting at pending_manager_approval, raised by a Loan Officer who is
 * NOT whoever the test authenticates next — so the §14 self-approval guard
 * does not fire spuriously.
 */
function submittedLoan(string $product = 'BODA_WC', string $category = 'BODA'): App\Models\Loan
{
    officerAt('Kakonko', RoleName::LoanOfficer);

    $customer = eligibleCustomer($category);
    $loanProduct = App\Models\LoanProduct::query()->with('repaymentSchedules')->where('code', $product)->sole();

    $payload = loanPayload($customer, [
        'loanProductId' => $loanProduct->getKey(),
        'repaymentScheduleId' => $loanProduct->repaymentSchedules->first()->getKey(),
        'principalAmount' => $loanProduct->min_amount,
        'tenureDays' => $loanProduct->min_tenure_days,
    ]);

    test()->postJson('/api/v1/loans', $payload)->assertCreated();

    return App\Models\Loan::query()->latest('id')->firstOrFail();
}

/**
 * Clears the zone tier — stage two of the client's approval chain.
 *
 * A separate identity from the branch manager on purpose: the chain exists so
 * that two different people look at a loan, and a helper that quietly used one
 * user for both would make every downstream test pass under a rule the system
 * is supposed to refuse.
 */
function approveAtZone(App\Models\Loan $loan): App\Models\Loan
{
    officerAt('Head Office', RoleName::ZoneManager);

    test()->postJson("/api/v1/loans/{$loan->id}/approval/decide", ['decision' => 'approved'])->assertOk();

    return $loan->refresh();
}

/** A mandate-product loan approved through branch and zone, waiting on its OTP. */
function approvedMandateLoan(): App\Models\Loan
{
    $loan = submittedLoan(product: 'SALARY_ADVANCE', category: 'PUBLIC_SERVANT');

    officerAt('Kakonko', RoleName::BranchManager);
    test()->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertOk();

    // The mandate opens when the stage BEFORE credit clears, because credit is
    // the stage that requires a live mandate.
    approveAtZone($loan);

    officerAt('Kakonko', RoleName::LoanOfficer);

    return $loan->refresh();
}

/**
 * Takes a decision at whatever stage a loan is at, as the current user.
 *
 * Shared rather than owned by one test file: the approval chain is now touched
 * by the workflow tests, the payment-reference tests and everything Batch 2
 * onward adds, and a second copy would be a second thing to keep in step with
 * the endpoint.
 */
function decide(App\Models\Loan $loan, string $decision, ?string $reason = null): Illuminate\Testing\TestResponse
{
    return test()->postJson("/api/v1/loans/{$loan->id}/approval/decide", array_filter([
        'decision' => $decision,
        'reason' => $reason,
    ]));
}

/**
 * What the approval panel reports for a loan: its stage, its chain, and what
 * the current user may do about it.
 *
 * @return array<string, mixed>
 */
function approvalState(App\Models\Loan $loan): array
{
    return test()->getJson("/api/v1/loans/{$loan->id}/approval")->assertOk()->json('data');
}

/** A loan approved through branch and zone, onto the credit-review step. */
function loanAtCreditReview(): App\Models\Loan
{
    $loan = submittedLoan();

    officerAt('Kakonko', RoleName::BranchManager);
    test()->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertOk();

    return approveAtZone($loan);
}

/** A loan that has cleared credit review and is waiting on Finance. */
function loanAtFinance(): App\Models\Loan
{
    $loan = loanAtCreditReview();

    officerAt('Kakonko', RoleName::CreditOfficer);
    test()->postJson("/api/v1/loans/{$loan->id}/telco-verify", ['passed' => true])->assertOk();

    return $loan->refresh();
}

/**
 * A valid loan product payload.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function productPayload(array $overrides = []): array
{
    $formula = App\Models\InterestFormula::query()->where('code', 'REDUCING')->sole();
    $weekly = App\Models\RepaymentSchedule::query()->where('code', 'WEEKLY')->sole();

    return array_merge([
        'name' => 'Market Trader Loan',
        'code' => 'MARKET_TRADER',
        'interestFormulaId' => $formula->getKey(),
        'interestRate' => '7.500',
        'minAmount' => '100000.00',
        'maxAmount' => '2000000.00',
        'minTenureDays' => 30,
        'maxTenureDays' => 180,
        'penaltyType' => 'percentage_of_overdue',
        'penaltyRate' => '5.000',
        'penaltyGraceDays' => 3,
        'penaltyCapAmount' => '50000.00',
        'requiresMandate' => false,
        'repaymentScheduleIds' => [$weekly->getKey()],
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Phase 6 — Ledger & Repayments
|--------------------------------------------------------------------------
*/

/**
 * Everything the ledger needs to exist: the loan foundation plus the §5 chart
 * of accounts (18 system accounts, the bank 8xxx rows, and one Teller Cash
 * account per branch).
 *
 * Nothing has been posted yet — this is an opened, empty set of books.
 */
function seedLedgerFoundation(): void
{
    seedLoanFoundation();
    /* The demo subclass — the same chart of accounts, plus the two bank
       accounts the treasury and ledger suites post through. Production runs
       the parent, which creates no bank account at all. */
    test()->seed(Database\Seeders\DemoBankAccountSeeder::class);
}

/**
 * Drives real money through the books: the demo staff, customers and loans,
 * then the capital injection, disbursements and repayments of
 * LedgerActivitySeeder.
 *
 * The seeders post through the same engine the API uses, so a test asserting
 * the seeded trial balance balances is testing LedgerService, not a fixture.
 * Call seedLedgerFoundation() first.
 */
function seedLedgerActivity(): void
{
    test()->seed(Database\Seeders\UserSeeder::class);
    test()->seed(Database\Seeders\CustomerSeeder::class);
    test()->seed(Database\Seeders\LoanSeeder::class);
    test()->seed(Database\Seeders\LedgerActivitySeeder::class);

    // The seeders resolve users of their own; without this the next request
    // would silently reuse whichever identity a seeder left in the guard.
    forgetAuthGuards();
}

/**
 * A single loan walked all the way to `active` through the real endpoints —
 * application, approval, telco verification, batch preparation, and finally
 * the disbursement callback that posts Dr Loan Receivable / Cr Principal.
 *
 * Built through the API rather than the factory so the loan under test has a
 * genuine schedule and a genuine disbursement entry behind it. A factory-made
 * "active" loan would have neither, and every allocation assertion would be
 * measuring a fixture instead of the engine.
 */
function activeLoan(): App\Models\Loan
{
    $loan = loanAtFinance();

    $finance = officerAt('Head Office', RoleName::Finance);

    test()->postJson("/api/v1/loans/{$loan->id}/prepare-disbursement", ['channel' => 'vodacom'])
        ->assertCreated();

    test()->postJson("/api/v1/loans/{$loan->id}/settle-disbursement", ['success' => true])
        ->assertOk();

    return $loan->fresh(['schedules', 'customer', 'branch']);
}

/**
 * Pays cash against a loan as a teller of its branch.
 *
 * Shared because more than one suite needs it: a Pest helper declared inside a
 * test file is not visible to the others.
 */
function payCash(App\Models\Loan $loan, string $amount): Illuminate\Testing\TestResponse
{
    officerAt($loan->branch->name, RoleName::Teller);

    return test()->postJson('/api/v1/payments/cash', [
        'loanId' => $loan->getKey(),
        'amount' => $amount,
    ]);
}

/**
 * Moves the clock to the day installment `$number` falls due.
 *
 * Needed since the client's advance ruling: a payment now settles only
 * installments that have REACHED their due date, and everything else is held as
 * a Customer Advance. A loan disbursed today has nothing due today, so a test
 * that wants to exercise settlement has to say when it is paying.
 *
 * Travels to the due date itself, not past it — the installment is due, and not
 * yet overdue, so no penalty is in play unless the test asks for one.
 */
function atDueDate(App\Models\Loan $loan, int $number = 1): App\Models\Loan
{
    $schedule = $loan->schedules()->where('installment_number', $number)->firstOrFail();

    test()->travelTo($schedule->due_date->copy()->startOfDay()->addHours(9));

    return $loan;
}

/**
 * An active loan whose first `$number` installments have fallen due.
 *
 * The ordinary starting point for a repayment test: money arriving against a
 * debt that is actually payable.
 */
function matureLoan(int $number = 1): App\Models\Loan
{
    return atDueDate(activeLoan(), $number);
}

/**
 * An active loan every installment of which has fallen due.
 *
 * What a test needs to settle a loan in full. Paying the whole balance BEFORE
 * the final due date no longer closes the loan — the surplus is held as a
 * Customer Advance and consumed installment by installment — so a settlement
 * test has to put the clock where the debt actually is.
 */
function fullyDueLoan(): App\Models\Loan
{
    $loan = activeLoan();

    return atDueDate($loan, (int) $loan->schedules->max('installment_number'));
}

/**
 * The money owed on a loan's first `$count` installments, computed from the
 * schedule rather than written by hand — the same figure the allocator will
 * walk.
 */
function installmentTotal(App\Models\Loan $loan, int $count = 1): App\Support\Money
{
    return App\Support\Money::sum(
        $loan->schedules->sortBy('installment_number')->take($count)
            ->map(fn (App\Models\LoanSchedule $s): App\Support\Money => $s->outstandingTotal()),
    );
}

/*
|--------------------------------------------------------------------------
| Phase 7 — HR, Payroll & Commission
|--------------------------------------------------------------------------
*/

/**
 * The staff book on top of a live ledger: the demo users, customers, loans and
 * ledger activity, then one staff profile per user plus the seeded staff loan,
 * advance and performance reviews.
 *
 * The ledger activity matters as much as the staff do — a commission pool is
 * computed from branch profit, so a book with no income produces nothing but
 * zero pools and tests nothing.
 */
function seedStaffBook(): void
{
    seedLedgerFoundation();
    seedLedgerActivity();

    /*
     * Bands before staff. An advance is priced by the band its amount falls
     * into, so a seeded advance created without one would carry no terms and
     * payroll would have nothing to recover against.
     */
    test()->seed(Database\Seeders\SalaryAdvanceCategorySeeder::class);
    test()->seed(Database\Seeders\StaffSeeder::class);

    forgetAuthGuards();
}

/** The period the seeded ledger activity falls in — see PayrollSeeder. */
function currentPeriod(): string
{
    return now()->format('Y-m');
}

/**
 * The staff profile belonging to a seeded demo user, by phone.
 *
 * Phones rather than names because UserSeeder keys on them, and a test that
 * asked for "the Teller" would break the moment a second one is seeded.
 */
function staffFor(string $phone): App\Models\StaffProfile
{
    return App\Models\StaffProfile::query()
        ->with('user')
        ->whereHas('user', fn ($q) => $q->where('phone', $phone))
        ->firstOrFail();
}

/**
 * Authenticates one of the seeded demo users — the ones who actually hold
 * staff profiles, unlike the factory users `officerAt()` makes.
 *
 * Payroll tests need this: a run pays every active staff profile, and an actor
 * without one would be authorising their own payslip into existence.
 */
function actingAsSeededUser(string $phone): User
{
    $user = User::query()->where('phone', $phone)->firstOrFail();

    forgetAuthGuards();
    Sanctum::actingAs($user, ['*']);

    return $user;
}

/** Catherine Massawe — Finance: finalizes payroll, pays it, disburses advances. */
function actingAsFinance(): User
{
    return actingAsSeededUser('0754000003');
}

/** Grace Mbwana — HR: registers staff, generates payroll, decides advances. */
function actingAsHr(): User
{
    return actingAsSeededUser('0754000007');
}

/**
 * A finalized payroll run for the current period, with its commission pools
 * already computed — the state most ledger assertions start from.
 */
function finalizedPayrollRun(): App\Models\PayrollRun
{
    $finance = actingAsFinance();
    app(App\Domain\Hr\Actions\GenerateCommissionPoolsAction::class)->handle(currentPeriod(), $finance);

    $hr = actingAsHr();
    $run = app(App\Domain\Hr\Actions\GeneratePayrollAction::class)->handle(currentPeriod(), $hr);

    /*
     * HR approves before Finance posts — §16.7, and §16.1's moment after which
     * the figures can no longer change. Added in Module 7: the run used to go
     * straight from HR's draft to Finance's posting, so there was no point at
     * which the figures became the agreed figures.
     */
    app(App\Domain\Hr\Actions\ApprovePayrollAction::class)->handle($run, $hr);

    $finance = actingAsFinance();

    return app(App\Domain\Hr\Actions\FinalizePayrollAction::class)->handle($run->refresh(), $finance);
}

/*
|--------------------------------------------------------------------------
| Phase 9 — Webhook signing
|--------------------------------------------------------------------------
*/

/** The payments provider's signing secret in the test environment. */
function paymentsSecret(): string
{
    return (string) config('webhooks.providers.payments.secret');
}

/** The Vodacom disbursement provider's signing secret. */
function vodacomSecret(): string
{
    return (string) config('webhooks.providers.vodacom.secret');
}

/**
 * Signs a payload the way a provider would, returning the headers alongside
 * the exact body they were computed over.
 *
 * The body is encoded ONCE and both signed and sent, because a signature is
 * over bytes: re-encoding for the request could change key order or whitespace
 * and would verify against a payload that was never transmitted.
 *
 * @param array<string, mixed> $payload
 * @return array{body: string, headers: array<string, string>}
 */
function signedCallback(array $payload, string $secret, string $header, ?int $timestamp = null): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp ??= time();

    return [
        'body' => $body,
        'headers' => [
            $header => hash_hmac('sha256', $timestamp.'.'.$body, $secret),
            'X-Webhook-Timestamp' => (string) $timestamp,
        ],
    ];
}

/**
 * POSTs a pre-signed raw body, bypassing the test client's JSON re-encoding.
 *
 * @param array{body: string, headers: array<string, string>} $signed
 */
function postSigned(string $uri, array $signed): Illuminate\Testing\TestResponse
{
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];

    foreach ($signed['headers'] as $key => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $key))] = $value;
    }

    return test()->call('POST', $uri, [], [], [], $server, $signed['body']);
}

/**
 * A signed callback to the payments webhook — the shape most tests want.
 *
 * @param array<string, mixed> $payload
 */
function postPaymentWebhook(array $payload): Illuminate\Testing\TestResponse
{
    return postSigned(
        '/webhooks/payments',
        signedCallback($payload, paymentsSecret(), (string) config('webhooks.providers.payments.header')),
    );
}

/**
 * A signed callback to the Vodacom disbursement webhook.
 *
 * @param array<string, mixed> $payload
 */
function postDisbursementWebhook(array $payload): Illuminate\Testing\TestResponse
{
    return postSigned(
        '/webhooks/vodacom/disbursement-status',
        signedCallback($payload, vodacomSecret(), (string) config('webhooks.providers.vodacom.header')),
    );
}

/**
 * Installment rows for a loan, written straight rather than generated.
 *
 * The generator derives its own amounts from the product's terms, which is what
 * you want when testing the generator and exactly what you do not want when
 * testing a sum: a balance assertion should state the figure it expects, not
 * inherit whatever the schedule happened to produce.
 *
 * @param array<string, string> $amounts
 */
function scheduleRows(App\Models\Loan $loan, int $count, array $amounts = []): void
{
    $amounts = array_merge([
        'principal_due' => '50000.00',
        'interest_due' => '7500.00',
        'penalty_due' => '0.00',
        'principal_paid' => '0.00',
        'interest_paid' => '0.00',
        'penalty_paid' => '0.00',
    ], $amounts);

    $start = (int) App\Models\LoanSchedule::query()->where('loan_id', $loan->getKey())->max('installment_number');

    foreach (range(1, $count) as $i) {
        App\Models\LoanSchedule::query()->create(array_merge($amounts, [
            'loan_id' => $loan->getKey(),
            'installment_number' => $start + $i,
            'due_date' => now()->addWeeks($start + $i)->toDateString(),
            'status' => 'pending',
        ]));
    }
}
