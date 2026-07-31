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
    test()->seed(PermissionSeeder::class);
    test()->seed(RoleSeeder::class);
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
 */
function seedCustomerFoundation(): void
{
    seedOrganization();
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

    return array_merge([
        'nidaNumber' => $nidaNumber,
        'nidaVerifiedAt' => now()->toIso8601String(),
        'otpVerifiedAt' => now()->toIso8601String(),
        'faceVerifiedAt' => now()->toIso8601String(),

        'firstName' => $identity->firstName,
        'middleName' => $identity->middleName,
        'lastName' => $identity->lastName,
        'dob' => $identity->dob,
        'gender' => $identity->gender->value,

        'phone' => '0755123456',
        'maritalStatus' => 'married',
        'regionId' => App\Models\Region::query()->where('name', 'Kigoma')->value('id'),
        'districtId' => null,
        'wardId' => null,
        'streetId' => null,
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
    test()->postJson('/api/v1/customers', registrationPayload($overrides))->assertCreated();

    // firstOrFail, not sole(): a test that registers two customers would
    // otherwise trip sole()'s "multiple records" guard on the second call.
    return App\Models\Customer::query()->latest('id')->firstOrFail();
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

/** A mandate-product loan approved and waiting on its OTP. */
function approvedMandateLoan(): App\Models\Loan
{
    $loan = submittedLoan(product: 'SALARY_ADVANCE', category: 'PUBLIC_SERVANT');

    officerAt('Kakonko', RoleName::BranchManager);
    test()->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertOk();

    officerAt('Kakonko', RoleName::LoanOfficer);

    return $loan->refresh();
}

/** A loan approved onto the credit-review step. */
function loanAtCreditReview(): App\Models\Loan
{
    $loan = submittedLoan();

    officerAt('Kakonko', RoleName::BranchManager);
    test()->postJson("/api/v1/loans/{$loan->id}/approve-manager", ['decision' => 'approve'])->assertOk();

    return $loan->refresh();
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
    test()->seed(Database\Seeders\ChartOfAccountSeeder::class);
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

    $finance = actingAsFinance();

    return app(App\Domain\Hr\Actions\FinalizePayrollAction::class)->handle($run, $finance);
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
