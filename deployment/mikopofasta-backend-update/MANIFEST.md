# Manifest

52 files. Paths are relative to the Laravel application root — copy the
package contents into the root and every file lands where it belongs.

Baseline: commit **`d531271`**. NEW = does not exist at that commit.

| FILE PATH | NEW/MODIFIED | PURPOSE |
|---|---|---|
| `app/Domain/Customers/Actions/AssignCustomerCategoryAction.php` | MODIFIED | Validates a customer-type assignment against the customer’s own columns |
| `app/Domain/Customers/Actions/ManageCustomerCategoryAction.php` | MODIFIED | Derives the type code from the name; presence-keyed updates; form_title and optional_documents |
| `app/Domain/Customers/Actions/RegisterCustomerAction.php` | MODIFIED | Mirrors marital status onto the enum column; face_verified_at is always null at creation |
| `app/Domain/Customers/Enums/DynamicFieldType.php` | MODIFIED | Adds the currency and boolean field types |
| `app/Domain/Customers/Policies/CustomerCategoryPolicy.php` | MODIFIED | Customer-type writes restricted to the Super Administrator |
| `app/Domain/Customers/Services/DynamicFormValidator.php` | MODIFIED | Conditional rules, live data sources, cascade parentage, currency and boolean |
| `app/Domain/Customers/Services/KycEvaluator.php` | MODIFIED | Recognises the ID type/number pair; adds the identityDocumentFile requirement |
| `app/Domain/Customers/Support/MaritalStatusMirror.php` | NEW | Keeps marital_status in step with marital_status_id |
| `app/Domain/Customers/Support/StructuredRegistrationField.php` | NEW | Allowlist of customer fields a configured registration question may write to |
| `app/Domain/Loans/Actions/ManageLoanProductAction.php` | MODIFIED | Persists the new loan-product reference fields |
| `app/Domain/Loans/Enums/LoanStatus.php` | MODIFIED | State groupings behind the branch customer-status counts |
| `app/Http/Controllers/Customers/CustomerCategoryController.php` | MODIFIED | Carries the new customer-type attributes through |
| `app/Http/Controllers/Customers/CustomerController.php` | MODIFIED | Mirrors marital_status when a profile is edited |
| `app/Http/Controllers/Loans/LoanProductBranchController.php` | NEW | Assign and unassign branches for a loan product |
| `app/Http/Controllers/Loans/LoanProductController.php` | MODIFIED | Carries the new loan-product fields |
| `app/Http/Controllers/MasterData/MasterDataController.php` | MODIFIED | all() — every reference list in one response (the HTTP 429 fix); ID-type extra column |
| `app/Http/Controllers/Organization/BranchController.php` | MODIFIED | Five mutually exclusive customer-status counts on the branch list |
| `app/Http/Requests/Customers/CustomerCategoryRequest.php` | MODIFIED | Validates the richer registration-field contract and whole-schema rules |
| `app/Http/Requests/Customers/RegisterCustomerRequest.php` | MODIFIED | Accepts the identity pair; refuses a claimed faceVerifiedAt |
| `app/Http/Requests/Loans/LoanProductRequest.php` | MODIFIED | Validates the new loan-product fields |
| `app/Http/Resources/BranchResource.php` | MODIFIED | Emits the customer-status counts only when the query asked for them |
| `app/Http/Resources/CustomerCategoryResource.php` | MODIFIED | Exposes formTitle, optionalDocuments and the requires* flags |
| `app/Http/Resources/CustomerRegistrationDraftResource.php` | MODIFIED | Draft payload records emitted as JSON objects, never [] |
| `app/Http/Resources/CustomerResource.php` | MODIFIED | dynamicFormData as an object; identity and demographic fields |
| `app/Http/Resources/LoanProductResource.php` | MODIFIED | Exposes the new loan-product fields |
| `app/Http/Resources/MasterDataResource.php` | MODIFIED | Exposes documentTypeId for ID types |
| `app/Models/Branch.php` | MODIFIED | customers() relation and the count property docblocks |
| `app/Models/CustomerCategory.php` | MODIFIED | Fillable and casts for the new columns |
| `app/Models/LoanProduct.php` | MODIFIED | Fillable, casts and the branches() relation |
| `app/Models/MasterData/IdType.php` | MODIFIED | document_type_id fillable and the documentType relation |
| `app/Providers/AppServiceProvider.php` | MODIFIED | Rate limits split by method — reads 600/min, writes 120/min (the HTTP 429 fix) |
| `app/Support/JsonRecord.php` | NEW | Emits a key/value map as a JSON object, never [] |
| `app/Support/MasterDataRegistry.php` | NEW | The shared slug→model map for reference lists |
| `database/migrations/2026_09_01_000001_add_activation_to_customer_categories.php` | NEW | SCHEMA: customer_categories description, is_active (default true), sort_order |
| `database/migrations/2026_09_02_000001_add_reference_fields_to_loan_products.php` | NEW | SCHEMA: loan_products min/max_repayments, allows_deduction, approval_stage_id, topup_percent, take_home_percent |
| `database/migrations/2026_09_02_000002_create_loan_product_branches_table.php` | NEW | SCHEMA: loan_product_branches pivot (empty = every branch) |
| `database/migrations/2026_09_03_000001_add_registration_form_configuration.php` | NEW | SCHEMA: customer_categories form_title, optional_documents |
| `database/migrations/2026_09_04_000001_link_id_types_to_document_types.php` | NEW | SCHEMA: id_types.document_type_id (which document evidences an identity type) |
| `routes/api.php` | MODIFIED | +4 routes (master-data batch, loan-product branches). Profit Distribution routes stripped |
| `tests/Feature/Customers/ConfiguredRegistrationFormTest.php` | NEW | Regression cover (optional in production) |
| `tests/Feature/Customers/CustomerRegistrationRequirementsTest.php` | MODIFIED | Regression cover (optional in production) |
| `tests/Feature/Customers/CustomerRegistrationTest.php` | MODIFIED | Regression cover (optional in production) |
| `tests/Feature/Customers/CustomerSearchAndRbacTest.php` | MODIFIED | Regression cover (optional in production) |
| `tests/Feature/Customers/CustomerTypeAdministrationTest.php` | NEW | Regression cover (optional in production) |
| `tests/Feature/Customers/DemographicPersistenceTest.php` | NEW | Regression cover (optional in production) |
| `tests/Feature/Customers/IdentityDocumentMappingTest.php` | NEW | Regression cover (optional in production) |
| `tests/Feature/Customers/RegistrationDraftShapeTest.php` | NEW | Regression cover (optional in production) |
| `tests/Feature/Customers/RegistrationLifecycleTest.php` | NEW | Regression cover (optional in production) |
| `tests/Feature/Loans/LoanRbacAndProductTest.php` | MODIFIED | Regression cover (optional in production) |
| `tests/Feature/MasterData/MasterDataBatchTest.php` | NEW | Regression cover (optional in production) |
| `tests/Feature/Organization/BranchCustomerStatusTest.php` | NEW | Regression cover (optional in production) |
| `tests/Pest.php` | MODIFIED | Regression cover (optional in production) |

---

## Deliberately excluded (Profit Distribution)

Verified absent — `grep` for each returns zero files.

**Modified in development, not shipped:** `app/Domain/Accounting/Actions/ClosePeriodAction.php`,
`app/Models/AccountingPeriod.php`, `app/Enums/AuditAction.php`,
`tests/Feature/Accounting/PeriodCloseTest.php`.

**New in development, not shipped:** `app/Http/Controllers/Accounting/DistributionSettingController.php`,
`app/Http/Requests/Accounting/UpdateDistributionSettingRequest.php`, `app/Models/DistributionSetting.php`,
`database/migrations/2026_08_27_000001_create_distribution_settings_table.php`,
`tests/Feature/Accounting/ProfitDistributionTest.php`.

**Shared file, stripped:** `routes/api.php` — the controller import and the two
`distribution-setting` routes removed, so the file boots on a server that has
neither the controller nor the table.

> Do not overwrite this `routes/api.php` with the development copy.

**Also excluded:** `database/migrations/2026_08_30_000004_extend_customer_categories_for_registration.php`.
Comment-only, already applied in production, and Laravel's `migrations` table
holds `id`, `migration`, `batch` — no checksum — so it is inert and unnecessary.

## No seeded business data

No migration inserts a row. The only occurrences of customer-type words in
`app/` are two lines of **documentation** in `ManageCustomerCategoryAction`
illustrating how a code is derived from a name (`Wajasiriamali -> WAJASIRIAMALI`).
Nothing seeds a customer type, sector, employer or document type.
