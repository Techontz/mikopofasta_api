# MikopoFasta — Backend Deployment Package

**Checkpoint:** Register Account + requirement-configuration architecture
**Built:** 2026-09-07
**Baseline:** commit `d531271` (mikopofasta_api, branch `phase-2`)
**Target:** api.m-kopatz.co.tz

## Verification behind this package

| Check | Result |
|---|---|
| Pest (full suite) | **1549 passed, 0 failed** (9587 assertions) |
| PHPStan level 6 (whole app) | clean |
| Laravel Pint | clean |
| Payment-channel tests | 19/19 |
| Browser (real Chrome, local stack) | 13/13 |

## What is DELIBERATELY NOT in this package

Production was probed before packaging: `/api/v1/distribution-setting` returns **404**
while `/api/v1/loan-products/{id}/branches`, `/api/v1/master-data`,
`/api/v1/registration/requirements` and `/api/v1/bank-accounts` all return **401**.
So the previous package is deployed and Profit Distribution is not — and it stays out.

Ten files are excluded for that reason. They are one dependency cluster; shipping
any of them without the rest would break period close or route registration:

- `app/Domain/Accounting/Actions/ClosePeriodAction.php`
- `app/Models/AccountingPeriod.php`
- `app/Http/Controllers/Accounting/DistributionSettingController.php`
- `app/Http/Requests/Accounting/UpdateDistributionSettingRequest.php`
- `app/Models/DistributionSetting.php`
- `app/Enums/AuditAction.php`
- `database/migrations/2026_08_27_000001_create_distribution_settings_table.php`
- `tests/Feature/Accounting/ProfitDistributionTest.php`
- `tests/Feature/Accounting/PeriodCloseTest.php`
- `routes/api.php`

`routes/api.php` is excluded because its ONLY difference from production is the two
`/distribution-setting` routes. Nothing in this checkpoint adds a route — Register
Account reuses the existing `/bank-accounts` endpoints. Production's routes file is
already correct.

Verified: no package file references `DistributionSetting`, and all 15 `AuditAction`
cases the package uses already exist in production's copy of that enum.

## Migrations

| Migration | State | Effect |
|---|---|---|
| `2026_08_30_000004_extend_customer_categories_for_registration.php` | already run | **Comment-only change.** Laravel will not re-run it; included so the file on disk matches source. |
| `2026_09_01_000001_add_activation_to_customer_categories.php` | already run | Shipped in the previous package. |
| `2026_09_02_000001_add_reference_fields_to_loan_products.php` | already run | Shipped in the previous package. |
| `2026_09_02_000002_create_loan_product_branches_table.php` | already run | Shipped in the previous package. |
| `2026_09_03_000001_add_registration_form_configuration.php` | already run | Shipped in the previous package. |
| `2026_09_04_000001_link_id_types_to_document_types.php` | already run | Shipped in the previous package. |
| `2026_09_06_000001_scope_registration_requirements_to_customer_category.php` | **NEW — will run** | Adds `customer_category_id` to `account_type_requirements`; makes 14 requirement columns nullable (NULL = inherit); swaps the unique index for `(account_type_id, customer_category_id)`. **Existing rows keep every value.** |
| `2026_09_06_000002_make_bank_accounts_company_payment_channels.php` | **NEW — will run** | Drops `bank_accounts.branch_id` (all values were already NULL); adds `account_type`, `usage`, `bank_id`, `mobile_money_provider_id`; adds two DB-generated uniqueness keys. **`chart_account_id`, journal entries and balances untouched.** |

Both new migrations were applied AND rolled back on a real MySQL database, with the
row values compared byte-for-byte before and after.

## Files (74)

### app/Domain/Customers/  (11)

- `app/Domain/Customers/Actions/AssignCustomerCategoryAction.php` — MODIFIED
- `app/Domain/Customers/Actions/ManageCustomerCategoryAction.php` — MODIFIED
- `app/Domain/Customers/Actions/RegisterCustomerAction.php` — MODIFIED
- `app/Domain/Customers/Enums/DynamicFieldType.php` — MODIFIED
- `app/Domain/Customers/Policies/CustomerCategoryPolicy.php` — MODIFIED
- `app/Domain/Customers/Services/AccountTypeRequirementResolver.php` — MODIFIED
- `app/Domain/Customers/Services/DynamicFormValidator.php` — MODIFIED
- `app/Domain/Customers/Services/KycEvaluator.php` — MODIFIED
- `app/Domain/Customers/Services/ResolvedRequirements.php` — NEW
- `app/Domain/Customers/Support/MaritalStatusMirror.php` — NEW
- `app/Domain/Customers/Support/StructuredRegistrationField.php` — NEW

### app/Domain/Loans/  (3)

- `app/Domain/Loans/Actions/ManageLoanProductAction.php` — MODIFIED
- `app/Domain/Loans/Enums/LoanStatus.php` — MODIFIED
- `app/Domain/Loans/Services/LoanEligibilityChecker.php` — MODIFIED

### app/Domain/Treasury/  (5)

- `app/Domain/Treasury/Actions/RegisterBankAccountAction.php` — MODIFIED
- `app/Domain/Treasury/Actions/UpdateBankAccountAction.php` — MODIFIED
- `app/Domain/Treasury/DTOs/BankAccountData.php` — MODIFIED
- `app/Domain/Treasury/Enums/AccountChannelType.php` — NEW
- `app/Domain/Treasury/Enums/AccountUsage.php` — NEW

### app/Http/Controllers/  (7)

- `app/Http/Controllers/Customers/CustomerCategoryController.php` — MODIFIED
- `app/Http/Controllers/Customers/CustomerController.php` — MODIFIED
- `app/Http/Controllers/Loans/LoanProductBranchController.php` — NEW
- `app/Http/Controllers/Loans/LoanProductController.php` — MODIFIED
- `app/Http/Controllers/MasterData/MasterDataController.php` — MODIFIED
- `app/Http/Controllers/Organization/BranchController.php` — MODIFIED
- `app/Http/Controllers/Treasury/BankController.php` — MODIFIED

### app/Http/Requests/  (5)

- `app/Http/Requests/Customers/CustomerCategoryRequest.php` — MODIFIED
- `app/Http/Requests/Customers/RegisterCustomerRequest.php` — MODIFIED
- `app/Http/Requests/Loans/LoanProductRequest.php` — MODIFIED
- `app/Http/Requests/Treasury/StoreBankAccountRequest.php` — MODIFIED
- `app/Http/Requests/Treasury/UpdateBankAccountRequest.php` — MODIFIED

### app/Http/Resources/  (7)

- `app/Http/Resources/BankAccountResource.php` — MODIFIED
- `app/Http/Resources/BranchResource.php` — MODIFIED
- `app/Http/Resources/CustomerCategoryResource.php` — MODIFIED
- `app/Http/Resources/CustomerRegistrationDraftResource.php` — MODIFIED
- `app/Http/Resources/CustomerResource.php` — MODIFIED
- `app/Http/Resources/LoanProductResource.php` — MODIFIED
- `app/Http/Resources/MasterDataResource.php` — MODIFIED

### app/Models/  (5)

- `app/Models/AccountTypeRequirement.php` — MODIFIED
- `app/Models/BankAccount.php` — MODIFIED
- `app/Models/Branch.php` — MODIFIED
- `app/Models/CustomerCategory.php` — MODIFIED
- `app/Models/LoanProduct.php` — MODIFIED

### app/Models/MasterData/  (1)

- `app/Models/MasterData/IdType.php` — MODIFIED

### app/Providers/  (1)

- `app/Providers/AppServiceProvider.php` — MODIFIED

### app/Support/  (2)

- `app/Support/JsonRecord.php` — NEW
- `app/Support/MasterDataRegistry.php` — NEW

### database/migrations/  (8)

- `database/migrations/2026_08_30_000004_extend_customer_categories_for_registration.php` — MODIFIED
- `database/migrations/2026_09_01_000001_add_activation_to_customer_categories.php` — NEW
- `database/migrations/2026_09_02_000001_add_reference_fields_to_loan_products.php` — NEW
- `database/migrations/2026_09_02_000002_create_loan_product_branches_table.php` — NEW
- `database/migrations/2026_09_03_000001_add_registration_form_configuration.php` — NEW
- `database/migrations/2026_09_04_000001_link_id_types_to_document_types.php` — NEW
- `database/migrations/2026_09_06_000001_scope_registration_requirements_to_customer_category.php` — NEW
- `database/migrations/2026_09_06_000002_make_bank_accounts_company_payment_channels.php` — NEW

### tests/Feature/Customers/  (10)

- `tests/Feature/Customers/ConfiguredRegistrationFormTest.php` — NEW
- `tests/Feature/Customers/CustomerRegistrationRequirementsTest.php` — MODIFIED
- `tests/Feature/Customers/CustomerRegistrationTest.php` — MODIFIED
- `tests/Feature/Customers/CustomerSearchAndRbacTest.php` — MODIFIED
- `tests/Feature/Customers/CustomerTypeAdministrationTest.php` — NEW
- `tests/Feature/Customers/DemographicPersistenceTest.php` — NEW
- `tests/Feature/Customers/IdentityDocumentMappingTest.php` — NEW
- `tests/Feature/Customers/RegistrationDraftShapeTest.php` — NEW
- `tests/Feature/Customers/RegistrationLifecycleTest.php` — NEW
- `tests/Feature/Customers/RequirementCompositionTest.php` — NEW

### tests/Feature/Expenses/  (1)

- `tests/Feature/Expenses/ExpenseRequestTest.php` — MODIFIED

### tests/Feature/Loans/  (2)

- `tests/Feature/Loans/GuarantorMinimumTest.php` — MODIFIED
- `tests/Feature/Loans/LoanRbacAndProductTest.php` — MODIFIED

### tests/Feature/MasterData/  (1)

- `tests/Feature/MasterData/MasterDataBatchTest.php` — NEW

### tests/Feature/Organization/  (1)

- `tests/Feature/Organization/BranchCustomerStatusTest.php` — NEW

### tests/Feature/Treasury/  (3)

- `tests/Feature/Treasury/BankAccountTest.php` — MODIFIED
- `tests/Feature/Treasury/BankMovementTest.php` — MODIFIED
- `tests/Feature/Treasury/CompanyPaymentChannelTest.php` — NEW

### tests/Pest.php/  (1)

- `tests/Pest.php` — MODIFIED

