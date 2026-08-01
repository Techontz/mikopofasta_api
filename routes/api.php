<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SystemConfigurationController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Customers\CustomerCategoryController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerDocumentController;
use App\Http\Controllers\Customers\CustomerRelationController;
use App\Http\Controllers\Customers\GroupController;
use App\Http\Controllers\Customers\KycController;
use App\Http\Controllers\Expenses\ExpenseCategoryController;
use App\Http\Controllers\Expenses\ExpenseRequestController;
use App\Http\Controllers\Hr\CommissionController;
use App\Http\Controllers\Hr\PayrollController;
use App\Http\Controllers\Hr\SalaryAdvanceController;
use App\Http\Controllers\Hr\StaffController;
use App\Http\Controllers\Hr\StaffPayController;
use App\Http\Controllers\Ledger\LedgerController;
use App\Http\Controllers\Loans\ChargeRegisterController;
use App\Http\Controllers\Loans\DisbursementCallbackController;
use App\Http\Controllers\Loans\LoanChargeController;
use App\Http\Controllers\Loans\LoanConfigurationController;
use App\Http\Controllers\Loans\LoanController;
use App\Http\Controllers\Loans\LoanProductController;
use App\Http\Controllers\Organization\BranchController;
use App\Http\Controllers\Organization\CompanyProfileController;
use App\Http\Controllers\Organization\GeographyController;
use App\Http\Controllers\Organization\RegionController;
use App\Http\Controllers\Organization\ZoneController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\Repayments\PaymentController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Treasury\BankController;
use App\Http\Controllers\Treasury\CapitalContributionController;
use App\Http\Controllers\Treasury\FloatTransferController;
use App\Http\Controllers\Treasury\HqTransactionController;
use App\Http\Controllers\Treasury\ShareholderController;
use App\Http\Controllers\UserController;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1
|--------------------------------------------------------------------------
|
| Mounted with the `api` middleware group under the `api/v1` prefix in
| bootstrap/app.php. Rate limiters are defined in
| App\Providers\AppServiceProvider::configureRateLimiting().
|
*/

Route::get('/health', function (): JsonResponse {
    return response()->json([
        'data' => [
            'service' => config('app.name'),
            'api_version' => 'v1',
            'environment' => config('app.env'),
            'status' => 'ok',
        ],
    ]);
})->name('health');

/*
|--------------------------------------------------------------------------
| Authentication (public)
|--------------------------------------------------------------------------
|
| Throttled far more tightly than the rest of the API: these are the endpoints
| an attacker brute-forces. `auth` is keyed on phone+IP, `password-reset` on
| email+IP, so one attacker cannot lock out an unrelated user by exhausting a
| shared bucket.
|
*/
Route::prefix('auth')->name('auth.')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:auth')
        ->name('login');

    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset')
        ->name('forgot-password');

    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset')
        ->name('reset-password');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::post('/change-password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:auth')
            ->name('change-password');
    });

    /*
     * User administration — standard CRUD (§15) plus the status toggle.
     * Authorization is enforced per-action by UserPolicy rather than by a
     * blanket `permission:` middleware, so the policy stays the single place
     * the rule is written.
     */
    Route::apiResource('users', UserController::class);
    Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])
        ->name('users.status');

    /*
     * Roles are a fixed set (§14) — index/show plus the permission matrix
     * update. There is deliberately no store or destroy.
     */
    Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('/roles/{role}', [RoleController::class, 'show'])->name('roles.show');
    Route::put('/roles/{role}/permissions', [RoleController::class, 'updatePermissions'])
        ->name('roles.permissions.update');

    Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');

    /*
    |--------------------------------------------------------------------------
    | Organization (spec §2.2, §12)
    |--------------------------------------------------------------------------
    |
    | Reads are open to any authenticated user — branches, zones, regions and
    | the address chain are reference data the whole application depends on,
    | and a Loan Officer holds no admin permission. Which branches come back is
    | narrowed per user by BranchScope (§13). Writes require
    | `admin.org_settings`, enforced by the policies.
    |
    */

    // Declared before the {branch} resource routes so "hierarchy" is not
    // captured as a branch id.
    Route::get('/branches/hierarchy', [BranchController::class, 'hierarchy'])->name('branches.hierarchy');
    Route::post('/branches/{branch}/head-office', [BranchController::class, 'setHeadOffice'])
        ->name('branches.head-office');
    Route::apiResource('branches', BranchController::class);

    Route::apiResource('zones', ZoneController::class);
    Route::apiResource('regions', RegionController::class);

    Route::get('/company-profile', [CompanyProfileController::class, 'show'])->name('company-profile.show');
    Route::put('/company-profile', [CompanyProfileController::class, 'update'])->name('company-profile.update');

    // Address lookups for the customer registration wizard (§2.4).
    Route::get('/districts', [GeographyController::class, 'districts'])->name('districts.index');
    Route::get('/wards', [GeographyController::class, 'wards'])->name('wards.index');
    Route::get('/streets', [GeographyController::class, 'streets'])->name('streets.index');

    /*
    |--------------------------------------------------------------------------
    | Customers & KYC (spec §2.3, §2.4, §9, §15.1)
    |--------------------------------------------------------------------------
    |
    | Reads need `customers.view`, writes `customers.manage`, and approval
    | decisions the separate `customers.approve` grant. Everything is branch
    | scoped (§13). Categories are organization configuration and sit behind
    | `admin.org_settings`, matching where the frontend files them.
    |
    */

    // The KYC identity flow. Declared before the {customer} routes so
    // "nida-lookup" is not captured as a customer id.
    Route::post('/customers/nida-lookup', [KycController::class, 'lookup'])->name('customers.nida-lookup');
    Route::post('/customers/nida-otp-verify', [KycController::class, 'verifyOtp'])->name('customers.nida-otp-verify');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

    Route::get('/customers/{customer}/kyc-status', [CustomerController::class, 'kycStatus'])->name('customers.kyc-status');
    Route::post('/customers/{customer}/additional-data', [KycController::class, 'additionalData'])->name('customers.additional-data');
    Route::put('/customers/{customer}/category', [KycController::class, 'assignCategory'])->name('customers.category');
    Route::post('/customers/{customer}/face-verify', [KycController::class, 'faceVerify'])->name('customers.face-verify');

    Route::post('/customers/{customer}/approve', [CustomerController::class, 'approve'])->name('customers.approve');
    Route::post('/customers/{customer}/reject', [CustomerController::class, 'reject'])->name('customers.reject');
    Route::post('/customers/{customer}/freeze', [CustomerController::class, 'freeze'])->name('customers.freeze');
    Route::post('/customers/{customer}/unfreeze', [CustomerController::class, 'unfreeze'])->name('customers.unfreeze');
    Route::patch('/customers/{customer}/status', [CustomerController::class, 'setStatus'])->name('customers.status');

    Route::get('/customers/{customer}/documents', [CustomerDocumentController::class, 'index'])->name('customers.documents.index');
    Route::post('/customers/{customer}/documents', [CustomerDocumentController::class, 'store'])->name('customers.documents.store');
    Route::delete('/customers/{customer}/documents/{document}', [CustomerDocumentController::class, 'destroy'])->name('customers.documents.destroy');

    Route::get('/customers/{customer}/guarantors', [CustomerRelationController::class, 'guarantors'])->name('customers.guarantors.index');
    Route::post('/customers/{customer}/guarantors', [CustomerRelationController::class, 'storeGuarantor'])->name('customers.guarantors.store');
    Route::delete('/customers/{customer}/guarantors/{guarantor}', [CustomerRelationController::class, 'destroyGuarantor'])->name('customers.guarantors.destroy');

    Route::get('/customers/{customer}/next-of-kin', [CustomerRelationController::class, 'nextOfKin'])->name('customers.next-of-kin.index');
    Route::post('/customers/{customer}/next-of-kin', [CustomerRelationController::class, 'storeNextOfKin'])->name('customers.next-of-kin.store');
    Route::delete('/customers/{customer}/next-of-kin/{nextOfKin}', [CustomerRelationController::class, 'destroyNextOfKin'])->name('customers.next-of-kin.destroy');

    Route::get('/customers/{customer}/notes', [CustomerRelationController::class, 'notes'])->name('customers.notes.index');

    /*
     |--------------------------------------------------------------------------
     | Groups (sidebar → Group)
     |--------------------------------------------------------------------------
     |
     | Village banking groups. Reads behind customers.view, writes behind
     | customers.manage, enforced by GroupPolicy — a group is a set of
     | customers, so it rides on the customer grants rather than adding a
     | fourth. Membership rules live in GroupService.
     */
    Route::get('/groups', [GroupController::class, 'index'])->name('groups.index');
    Route::post('/groups', [GroupController::class, 'store'])->name('groups.store');
    Route::get('/groups/{group}', [GroupController::class, 'show'])->name('groups.show');
    Route::put('/groups/{group}', [GroupController::class, 'update'])->name('groups.update');
    Route::delete('/groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy');

    Route::post('/groups/{group}/members', [GroupController::class, 'addMember'])->name('groups.members.store');
    Route::patch('/groups/{group}/members/{member}', [GroupController::class, 'updateMember'])
        ->name('groups.members.update');
    Route::delete('/groups/{group}/members/{member}', [GroupController::class, 'removeMember'])
        ->name('groups.members.destroy');
    Route::post('/customers/{customer}/notes', [CustomerRelationController::class, 'storeNote'])->name('customers.notes.store');

    Route::apiResource('customer-categories', CustomerCategoryController::class)
        ->parameters(['customer-categories' => 'category']);

    /*
    |--------------------------------------------------------------------------
    | Loan Origination (spec §2.3, §2.5, §6, §10, §15.2)
    |--------------------------------------------------------------------------
    |
    | Five distinct grants carry the §14 separation of duties, each held by a
    | different role: loans.view / loans.create / loans.approve /
    | loans.credit_review / loans.disburse. Everything is branch scoped (§13),
    | and cross-branch credit review additionally needs the explicit
    | loans.review_cross_branch grant.
    |
    | Product configuration sits behind admin.org_settings, matching where the
    | frontend files it.
    |
    */

    // Configuration and lookups the application form needs.
    Route::get('/interest-formulas', [LoanConfigurationController::class, 'interestFormulas'])
        ->name('interest-formulas.index');
    Route::get('/repayment-schedules', [LoanConfigurationController::class, 'repaymentSchedules'])
        ->name('repayment-schedules.index');

    /*
     * Settings → Interest Formulas / Repayment Schedules.
     *
     * The reads are the two lookups above — the loan application form is their
     * main caller. The writes sit behind `admin.org_settings`, enforced by
     * SystemConfigurationPolicy. See docs/modules/administration.md.
     *
     * A formula has no store and no destroy: its `code` is what the interest
     * engine branches on, so a fourth one is a code change. Schedules are open,
     * because `frequency_days` is a number the generator divides by.
     */
    Route::put('/interest-formulas/{formula}', [SystemConfigurationController::class, 'updateInterestFormula'])
        ->name('interest-formulas.update');

    Route::post('/repayment-schedules', [SystemConfigurationController::class, 'storeRepaymentSchedule'])
        ->name('repayment-schedules.store');
    Route::put('/repayment-schedules/{schedule}', [SystemConfigurationController::class, 'updateRepaymentSchedule'])
        ->name('repayment-schedules.update');
    Route::delete('/repayment-schedules/{schedule}', [SystemConfigurationController::class, 'destroyRepaymentSchedule'])
        ->name('repayment-schedules.destroy');

    Route::get('/customer-categories/{category}/eligibility', [LoanConfigurationController::class, 'eligibility'])
        ->name('customer-categories.eligibility');
    Route::put('/customer-categories/{category}/eligibility', [LoanConfigurationController::class, 'updateEligibility'])
        ->name('customer-categories.eligibility.update');

    Route::apiResource('loan-products', LoanProductController::class)
        ->parameters(['loan-products' => 'product']);

    /*
     * Loan Charges & Reserve (Settings → Loan Fee / Penalty / Reserve Setting).
     * Reads are open; every write sits behind admin.org_settings, enforced by
     * LoanChargePolicy. See docs/modules/loan-charges.md.
     */
    Route::get('/loan-fees', [LoanChargeController::class, 'loanFees'])
        ->name('loan-fees.index');
    Route::put('/loan-fees/{product}', [LoanChargeController::class, 'upsertLoanFee'])
        ->name('loan-fees.update');
    Route::delete('/loan-fees/{product}', [LoanChargeController::class, 'deleteLoanFee'])
        ->name('loan-fees.destroy');

    Route::get('/penalty-settings', [LoanChargeController::class, 'penaltySettings'])
        ->name('penalty-settings.index');
    Route::post('/penalty-settings', [LoanChargeController::class, 'storePenaltySetting'])
        ->name('penalty-settings.store');
    Route::delete('/penalty-settings/{penaltySetting}', [LoanChargeController::class, 'deletePenaltySetting'])
        ->name('penalty-settings.destroy');

    /*
     * The charge registers — sidebar → Penalty (Penalty List, Paid Penalty)
     * and Loan Fee (Deducted Income).
     *
     * Read-only, behind loans.view OR repayments.view via ChargeRegisterPolicy:
     * a penalty is both a term of the loan and money to be collected, and
     * requiring both grants would shut out each role from something it owns.
     *
     * `/loan-fees/income` does not collide with `/loan-fees/{product}` above:
     * that parameterised route is PUT and DELETE only, and there is no GET
     * form of it, so "income" can never be read as a product id. Should a
     * GET `/loan-fees/{product}` ever be added it must be declared after this
     * line. See docs/modules/penalties-and-fees.md.
     */
    Route::get('/penalties', [ChargeRegisterController::class, 'penalties'])
        ->name('penalties.index');
    Route::get('/penalties/paid', [ChargeRegisterController::class, 'paidPenalties'])
        ->name('penalties.paid');
    Route::get('/loan-fees/income', [ChargeRegisterController::class, 'deductedIncome'])
        ->name('loan-fees.income');

    Route::get('/reserve-setting', [LoanChargeController::class, 'reserveSetting'])
        ->name('reserve-setting.show');
    Route::put('/reserve-setting', [LoanChargeController::class, 'updateReserveSetting'])
        ->name('reserve-setting.update');

    /*
     * Capital (sidebar → Capital). Reads behind treasury.view, writes behind
     * treasury.manage, enforced by CapitalPolicy. See docs/modules/capital.md.
     */
    Route::apiResource('shareholders', ShareholderController::class)
        ->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('capital-contributions', CapitalContributionController::class)
        ->only(['index', 'store', 'destroy'])
        ->parameters(['capital-contributions' => 'contribution']);

    Route::get('/float-transfers', [FloatTransferController::class, 'index'])->name('float-transfers.index');
    Route::post('/float-transfers', [FloatTransferController::class, 'store'])->name('float-transfers.store');
    Route::post('/float-transfers/{transfer}/approve', [FloatTransferController::class, 'approve'])
        ->name('float-transfers.approve');
    Route::post('/float-transfers/{transfer}/reject', [FloatTransferController::class, 'reject'])
        ->name('float-transfers.reject');
    Route::delete('/float-transfers/{transfer}', [FloatTransferController::class, 'destroy'])
        ->name('float-transfers.destroy');

    /*
     * Expenses (sidebar → Expenses, Headquarters Expenses, and Settings →
     * Expense Categories). Reads behind treasury.view; the register is
     * administrator work and decisions are treasury.manage, all enforced by
     * ExpensePolicy. See docs/modules/expenses.md.
     */
    Route::apiResource('expense-categories', ExpenseCategoryController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['expense-categories' => 'category']);

    Route::get('/expense-requests', [ExpenseRequestController::class, 'index'])
        ->name('expense-requests.index');
    Route::post('/expense-requests', [ExpenseRequestController::class, 'store'])
        ->name('expense-requests.store');
    Route::get('/expense-requests/{expenseRequest}', [ExpenseRequestController::class, 'show'])
        ->name('expense-requests.show');
    Route::post('/expense-requests/{expenseRequest}/decide', [ExpenseRequestController::class, 'decide'])
        ->name('expense-requests.decide');
    Route::patch('/expense-requests/{expenseRequest}/comment', [ExpenseRequestController::class, 'comment'])
        ->name('expense-requests.comment');
    Route::delete('/expense-requests/{expenseRequest}', [ExpenseRequestController::class, 'destroy'])
        ->name('expense-requests.destroy');

    /*
     * Headquarters Transaction (sidebar → Headquarters Transaction). The seven
     * head-office pots and the movements between them — outside the §5 chart of
     * accounts by design. Same treasury.view / treasury.manage pair as Capital,
     * enforced by CapitalPolicy. See docs/modules/headquarters.md.
     */
    Route::get('/hq-accounts', [HqTransactionController::class, 'accounts'])
        ->name('hq-accounts.index');

    Route::get('/hq-transactions', [HqTransactionController::class, 'index'])
        ->name('hq-transactions.index');
    Route::post('/hq-transactions', [HqTransactionController::class, 'store'])
        ->name('hq-transactions.store');
    Route::post('/hq-transactions/{transaction}/decide', [HqTransactionController::class, 'decide'])
        ->name('hq-transactions.decide');

    /*
     * Bank (sidebar → Bank). Register Account, Account Balance, Bank
     * Transaction, Approved Transaction and the two Transfer Balance screens.
     * Same treasury.view / treasury.manage pair, enforced by CapitalPolicy.
     * See docs/modules/bank.md.
     */
    Route::get('/bank-accounts', [BankController::class, 'accounts'])->name('bank-accounts.index');
    Route::post('/bank-accounts', [BankController::class, 'storeAccount'])->name('bank-accounts.store');
    Route::put('/bank-accounts/{bankAccount}', [BankController::class, 'updateAccount'])
        ->name('bank-accounts.update');
    Route::delete('/bank-accounts/{bankAccount}', [BankController::class, 'destroyAccount'])
        ->name('bank-accounts.destroy');

    Route::get('/bank-transactions', [BankController::class, 'transactions'])
        ->name('bank-transactions.index');
    Route::post('/bank-transactions', [BankController::class, 'storeTransaction'])
        ->name('bank-transactions.store');
    Route::post('/bank-transactions/{transaction}/decide', [BankController::class, 'decideTransaction'])
        ->name('bank-transactions.decide');

    Route::get('/bank-transfers', [BankController::class, 'transfers'])->name('bank-transfers.index');
    Route::post('/bank-transfers', [BankController::class, 'storeTransfer'])->name('bank-transfers.store');

    // Declared before the {loan} routes so it is not captured as a loan id.
    Route::post('/loans/check-eligibility', [LoanController::class, 'checkEligibility'])
        ->name('loans.check-eligibility');

    Route::get('/loans', [LoanController::class, 'index'])->name('loans.index');
    Route::post('/loans', [LoanController::class, 'store'])->name('loans.store');
    Route::get('/loans/{loan}', [LoanController::class, 'show'])->name('loans.show');

    Route::get('/loans/{loan}/schedule', [LoanController::class, 'schedule'])->name('loans.schedule');
    Route::get('/loans/{loan}/history', [LoanController::class, 'history'])->name('loans.history');
    Route::get('/loans/{loan}/topup-eligibility', [LoanController::class, 'topupEligibility'])
        ->name('loans.topup-eligibility');

    // The §10 workflow, in order.
    Route::post('/loans/{loan}/approve-manager', [LoanController::class, 'decide'])->name('loans.approve-manager');
    Route::post('/loans/{loan}/mandate/verify-otp', [LoanController::class, 'verifyMandate'])->name('loans.mandate.verify');
    Route::post('/loans/{loan}/mandate/retry', [LoanController::class, 'retryMandate'])->name('loans.mandate.retry');
    Route::post('/loans/{loan}/telco-verify', [LoanController::class, 'telcoVerify'])->name('loans.telco-verify');
    Route::post('/loans/{loan}/prepare-disbursement', [LoanController::class, 'prepareDisbursement'])
        ->name('loans.prepare-disbursement');
    Route::post('/loans/{loan}/retry-disbursement', [LoanController::class, 'retryDisbursement'])
        ->name('loans.retry-disbursement');

    // The authenticated twin of the §15.2 provider callback — what the
    // frontend's loan actions panel calls. Both reach the same action, so
    // there is one place a loan becomes active and one place it is posted.
    Route::post('/loans/{loan}/settle-disbursement', [DisbursementCallbackController::class, 'settle'])
        ->name('loans.settle-disbursement');
    Route::post('/loans/{loan}/close', [LoanController::class, 'close'])->name('loans.close');
    Route::post('/loans/{loan}/cancel', [LoanController::class, 'cancel'])->name('loans.cancel');

    /*
    |--------------------------------------------------------------------------
    | Repayments & Collections (spec §2.6, §7, §15.3)
    |--------------------------------------------------------------------------
    |
    | Four grants, deliberately separate (§14): repayments.view,
    | repayments.manage, repayments.cash_entry (the Teller's only write) and
    | repayments.reconcile (Finance alone). All intake funnels through
    | RecordRepaymentAction.
    |
    */

    // Declared before /payments/{payment} so these are not read as ids.
    Route::get('/payments/suspense', [PaymentController::class, 'suspense'])->name('payments.suspense');
    Route::post('/payments/cash', [PaymentController::class, 'cash'])
        ->middleware('idempotency')
        ->name('payments.cash');
    Route::post('/payments/unmatched', [PaymentController::class, 'unmatched'])->name('payments.unmatched');

    Route::post('/payments/suspense/{item}/allocate', [PaymentController::class, 'allocateSuspense'])
        ->name('payments.suspense.allocate');
    Route::post('/payments/suspense/{item}/investigate', [PaymentController::class, 'investigateSuspense'])
        ->name('payments.suspense.investigate');

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('/payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');

    // §7's overdue/penalty job. Cron-driven in production, manually invokable
    // by Finance — which is why it is an endpoint.
    Route::post('/loans/overdue/process', [PaymentController::class, 'runOverdueProcess'])
        ->name('loans.overdue.process');

    /*
    |--------------------------------------------------------------------------
    | Ledger (spec §2.7, §5, §8, §15.4)
    |--------------------------------------------------------------------------
    |
    | Read-only apart from the reversal workflow. Nothing here posts an entry:
    | entries are a consequence of a business event and LedgerService is the
    | only writer (§5).
    |
    */
    Route::get('/ledger/accounts', [LedgerController::class, 'accounts'])->name('ledger.accounts');
    Route::get('/ledger/accounts/{account}/entries', [LedgerController::class, 'accountEntries'])
        ->name('ledger.accounts.entries');

    Route::get('/ledger/trial-balance', [LedgerController::class, 'trialBalance'])->name('ledger.trial-balance');

    Route::get('/ledger/reversals', [LedgerController::class, 'reversals'])->name('ledger.reversals');
    Route::post('/ledger/reversals/{reversalRequest}/approve', [LedgerController::class, 'approveReversal'])
        ->name('ledger.reversals.approve');
    Route::post('/ledger/reversals/{reversalRequest}/reject', [LedgerController::class, 'rejectReversal'])
        ->name('ledger.reversals.reject');

    Route::get('/ledger/entries', [LedgerController::class, 'entries'])->name('ledger.entries');
    Route::get('/ledger/entries/{entry}', [LedgerController::class, 'entry'])->name('ledger.entries.show');
    Route::post('/ledger/entries/{entry}/reverse', [LedgerController::class, 'requestReversal'])
        ->name('ledger.entries.reverse');

    // §2.7's derived sub-ledgers: customers | loans | staff | branches.
    Route::get('/ledger/{dimension}/{id}', [LedgerController::class, 'subLedger'])
        ->whereIn('dimension', ['customers', 'loans', 'staff', 'branches'])
        ->whereNumber('id')
        ->name('ledger.sub-ledger');

    /*
    |--------------------------------------------------------------------------
    | HR, Payroll & Commission (spec §2.9, §11, §15.5)
    |--------------------------------------------------------------------------
    |
    | §14's separation of duties is visible in the grants rather than in the
    | paths: hr.view / hr.manage for the staff book and advances,
    | payroll.generate for HR's draft, and payroll.finalize for everything
    | Finance does — finalizing, paying, and disbursing an advance.
    |
    | Nothing here is branch-scoped. HR is an HQ function (§14 scopes it to all
    | branches): a company keeps one personnel record per employee, not one per
    | branch.
    |
    */

    // Declared before /staff/{staffProfile} so these are not read as ids.
    Route::get('/staff/advances', [StaffController::class, 'advances'])->name('staff.advances');

    /*
     * Salary Advance (sidebar → Salary Advance, and HRM → Staff salary advance
     * category, which reaches the same register).
     *
     * The lifecycle endpoints stay on /staff/advance/* where §15.5 put them;
     * these are the bands and the five list screens. Behind the HR policy —
     * reads on `hr.view`, band management on `hr.manage`.
     * See docs/modules/salary-advance.md.
     */
    Route::get('/salary-advance-categories', [SalaryAdvanceController::class, 'categories'])
        ->name('salary-advance-categories.index');
    Route::post('/salary-advance-categories', [SalaryAdvanceController::class, 'storeCategory'])
        ->name('salary-advance-categories.store');
    Route::put('/salary-advance-categories/{category}', [SalaryAdvanceController::class, 'updateCategory'])
        ->name('salary-advance-categories.update');
    Route::delete('/salary-advance-categories/{category}', [SalaryAdvanceController::class, 'destroyCategory'])
        ->name('salary-advance-categories.destroy');

    // Declared before the collection route so "repayments" is not read as a
    // filter value on it.
    Route::get('/salary-advances/repayments', [SalaryAdvanceController::class, 'repayments'])
        ->name('salary-advances.repayments');
    Route::get('/salary-advances', [SalaryAdvanceController::class, 'index'])
        ->name('salary-advances.index');
    /*
     * Staff loans — §14, and the same request → HR approval → Finance
     * disbursement route an advance takes (§16.7–16.8). Read-only until
     * Module 7: before it, only a seeder could create one and nothing could
     * ever close one.
     */
    Route::get('/staff/loans', [StaffController::class, 'loans'])->name('staff.loans');
    Route::post('/staff/loan/request', [StaffController::class, 'requestLoan'])->name('staff.loan.request');
    Route::post('/staff/loan/approve', [StaffController::class, 'approveLoan'])->name('staff.loan.approve');
    Route::post('/staff/loan/reject', [StaffController::class, 'rejectLoan'])->name('staff.loan.reject');
    Route::post('/staff/loan/disburse', [StaffController::class, 'disburseLoan'])->name('staff.loan.disburse');

    /*
     * What an employee draws and what is withheld (§10, §11), their payslips
     * (§17), the Staff Fund (§12) and their four §2B ledger views.
     *
     * The fund and the payslip index come before /staff/{staffProfile} in
     * spirit but not in the file — Laravel matches in declaration order, and
     * `staff-fund` and `payslips` are distinct paths, so neither can be
     * swallowed by the wildcard the way `staff/loans` could.
     */
    Route::get('/payslips', [StaffPayController::class, 'payslips'])->name('payslips.index');
    Route::get('/staff-fund', [StaffPayController::class, 'fund'])->name('staff-fund.show');

    Route::get('/staff/{staffProfile}/payslips', [StaffPayController::class, 'staffPayslips'])
        ->name('staff.payslips');
    Route::get('/staff/{staffProfile}/ledger', [StaffPayController::class, 'ledger'])
        ->name('staff.ledger');

    Route::get('/staff/{staffProfile}/allowances', [StaffPayController::class, 'allowances'])
        ->name('staff.allowances.index');
    Route::post('/staff/{staffProfile}/allowances', [StaffPayController::class, 'grantAllowance'])
        ->name('staff.allowances.store');
    Route::put('/staff-allowances/{allowance}', [StaffPayController::class, 'updateAllowance'])
        ->name('staff-allowances.update');
    Route::delete('/staff-allowances/{allowance}', [StaffPayController::class, 'revokeAllowance'])
        ->name('staff-allowances.destroy');

    Route::get('/staff/{staffProfile}/deductions', [StaffPayController::class, 'deductions'])
        ->name('staff.deductions.index');
    Route::post('/staff/{staffProfile}/deductions', [StaffPayController::class, 'recordDeduction'])
        ->name('staff.deductions.store');
    Route::delete('/staff-deductions/{deduction}', [StaffPayController::class, 'cancelDeduction'])
        ->name('staff-deductions.destroy');
    Route::get('/staff/performance', [StaffController::class, 'performance'])->name('staff.performance.index');
    Route::post('/staff/performance', [StaffController::class, 'recordPerformance'])->name('staff.performance.store');

    // §15.5 addresses an advance by id in the body, not in the path.
    Route::post('/staff/advance/request', [StaffController::class, 'requestAdvance'])->name('staff.advance.request');
    Route::post('/staff/advance/approve', [StaffController::class, 'approveAdvance'])->name('staff.advance.approve');
    Route::post('/staff/advance/reject', [StaffController::class, 'rejectAdvance'])->name('staff.advance.reject');

    // §11: disbursement is Finance's, never HR's.
    Route::post('/staff/advance/disburse', [StaffController::class, 'disburseAdvance'])->name('staff.advance.disburse');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{staffProfile}', [StaffController::class, 'show'])->name('staff.show');
    Route::put('/staff/{staffProfile}', [StaffController::class, 'update'])->name('staff.update');

    Route::get('/payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::post('/payroll/generate', [PayrollController::class, 'generate'])
        ->middleware('idempotency')
        ->name('payroll.generate');
    Route::get('/payroll/{run}', [PayrollController::class, 'show'])->name('payroll.show');
    /*
     * §16.7–16.8 in three endpoints: HR approves, Finance finalizes, Finance
     * pays. `approve` sits behind payroll.generate — HR's grant — so no single
     * role holds both halves of the control.
     */
    Route::post('/payroll/{run}/approve', [PayrollController::class, 'approve'])->name('payroll.approve');
    Route::post('/payroll/{run}/finalize', [PayrollController::class, 'finalize'])->name('payroll.finalize');
    Route::post('/payroll/{run}/pay', [PayrollController::class, 'pay'])
        ->middleware('idempotency')
        ->name('payroll.pay');

    Route::get('/commission', [CommissionController::class, 'index'])->name('commission.index');
    Route::post('/commission/generate', [CommissionController::class, 'generate'])->name('commission.generate');
    Route::get('/commission/branches/{branch}', [CommissionController::class, 'branch'])->name('commission.branch');

    /*
    |--------------------------------------------------------------------------
    | Administration & System Configuration (Settings)
    |--------------------------------------------------------------------------
    |
    | Notification templates and the audit trail. Interest formulas and
    | repayment schedules are the other two Settings screens and sit with the
    | loan configuration they are lookups for.
    |
    | Templates read open and write behind `admin.org_settings`. The audit trail
    | is read-only in the strongest sense — there is no store, update or destroy
    | route for it anywhere, because §2 makes it append-only and an endpoint
    | that could rewrite a row would defeat the only thing it is for. Reading it
    | needs `audit.view`, or `admin.org_settings` for an administrator who has
    | not been granted the narrower one.
    |
    | See docs/modules/administration.md.
    |
    */
    Route::get('/notification-templates', [SystemConfigurationController::class, 'notificationTemplates'])
        ->name('notification-templates.index');
    Route::post('/notification-templates', [SystemConfigurationController::class, 'storeNotificationTemplate'])
        ->name('notification-templates.store');
    Route::put('/notification-templates/{template}', [SystemConfigurationController::class, 'updateNotificationTemplate'])
        ->name('notification-templates.update');
    Route::delete(
        '/notification-templates/{template}',
        [SystemConfigurationController::class, 'destroyNotificationTemplate'],
    )->name('notification-templates.destroy');

    Route::get('/audit-logs', [SystemConfigurationController::class, 'auditLogs'])->name('audit-logs.index');

    /*
    |--------------------------------------------------------------------------
    | Reporting (spec §15.6)
    |--------------------------------------------------------------------------
    |
    | Read-only, all of them, behind the single `reports.view` grant. What a
    | user may see is decided by branch scope (§13) rather than by a per-report
    | permission — a Loan Officer and the Finance Director call the same
    | endpoint, and the officer's results are pinned to their own branch.
    |
    | §15.6 lists twenty-one paths of the form `/reports/<name>`; the {slug}
    | route serves exactly those paths, and `GET /reports` enumerates what
    | exists so the catalogue can never drift from the implementation.
    |
    */
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    /*
     * Export before show: `/reports/{slug}/export` would otherwise be matched
     * by the {slug} route with "export" as part of the slug, and the reader
     * would get a 404 for a report that exists.
     */
    Route::get('/reports/{slug}/export', [ReportController::class, 'export'])
        ->where('slug', '[a-z0-9-]+')
        ->name('reports.export');

    Route::get('/reports/{slug}', [ReportController::class, 'show'])
        ->where('slug', '[a-z0-9-]+')
        ->name('reports.show');
});

/*
|--------------------------------------------------------------------------
| Signed KYC file access
|--------------------------------------------------------------------------
|
| Outside the Sanctum group on purpose. These URLs are handed to a browser as
| a plain navigation (an <img> src, a download link), which cannot carry an
| Authorization header — so the signature IS the credential. Spec §1 calls for
| exactly this: "signed, time-limited URLs only, never public disk".
|
| Links expire after KycDocumentStorage::URL_TTL_MINUTES.
|
*/
Route::middleware('signed')->group(function (): void {
    Route::get('/customers/{customer}/documents/{document}/download', [CustomerDocumentController::class, 'download'])
        ->name('customers.documents.download');

    Route::get('/customers/{customer}/photo', [CustomerDocumentController::class, 'photo'])
        ->name('customers.photo');
});
