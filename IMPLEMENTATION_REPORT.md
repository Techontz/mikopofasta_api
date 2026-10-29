# M-KopaCredit enhancements: implementation report

This report covers the requirements in the "Master Implementation Prompt — M-KopaCredit System Enhancements". The work lives on stacked branches in both repositories (`mikopofasta_api`, `mikopofasta_web`). Each branch builds on the one before it, so they must be merged in this order:

| Order | Branch | Scope |
|---|---|---|
| 1 | `feature/phase1-customer-integrity` | §2 duplicates across branches, branch transfer |
| 2 | `feature/phase2-permissions` | §12.3, §12.4, §13, §14, §15 |
| 3 | `feature/phase3-loans-payments` | §3.2, §6, §7, §8, §9.1, §16.1–16.4 |
| 4 | `feature/phase5-dashboards-reports-hrm` | §10, §11, §12.1–12.2, §16.5, §17 |
| 5 | `feature/phase7-integrations` | §3.4, §4, §5, §2.2/§18 badge, this report |

## 1. Modified modules

- **Customers:** cross-branch duplicate checks with a verification queue; branch transfer that has to be approved; a "changes pending approval" flag.
- **Access control:** Super Admin only for Roles & Permissions; restrictions on what HR can assign; multi-role users with Switch Role; HRM permissions limited to the HR, Admin and Super Admin roles; report access by department.
- **Loans:**
  - Start Date and the repayment schedule built from it.
  - Return to Previous Stage.
  - `status_changed_at`, used by the Ready to Pay Out filters.
  - Discount/Waiver.
  - E-Mandate collections and reports.
  - Mandate cancellation on top-up.
  - Phone Bond.
- **Payments:** optional slip number; deposit receipt upload and View Receipt; Cash Verification tab rules; Bank Reconciliation summary; Customer Payment ID payments to E-Mandate loans held in suspense.
- **Accounting:** new LOAN DISCOUNT / WAIVER EXPENSE account (5210), included in branch P&L expenses, cash-flow "loan_other" and the Finance dashboard.
- **Reports:**
  - Repayment, Default and Collection accept a period.
  - File New Loans by month and customer status.
  - Statement shows the newest rows first, the Loan ID and discounts.
  - Portfolio shows amounts.
  - Print/PDF layout.
- **Dashboards:**
  - Summary cards are no longer clickable.
  - Red indicators on pending counts.
  - Credit pipeline and money-first cards.
  - Finance card icons removed.
  - CRM KYC pipeline, with the customer count removed.
  - Pending Approvals on the HR and Admin dashboards.
- **HRM:** employee KYC approval; locked personal details with audited changes; CV, certificate and contract uploads.

## 2. Database changes (migrations dated 2026_10_29 / 2026_10_30)

| Migration | Change |
|---|---|
| `create_customer_duplicate_reviews_table` | Held registrations awaiting duplicate verification |
| `create_customer_branch_transfers_table` | Transfer requests and approvals; permissions `customers.transfer`, `customers.approve_transfer` |
| `create_employee_roles_table` | Additional roles per employee; `personal_access_tokens.active_role_id` |
| `split_report_access_by_department` | `reports.operations/loans/customers/portfolio` for the system roles; HR loses loan reports |
| `add_start_date_to_loans_table` | `loans.start_date` |
| `add_status_changed_at_to_loans_table` | `loans.status_changed_at` (backfilled from `updated_at`) |
| `add_receipt_to_teller_deposits_table` | `slip_number` becomes nullable; receipt file columns |
| `create_loan_discounts_table` | Discount/waiver requests; permissions `loans.discount_request`, `loans.discount_approve` |
| `grant_pending_approvals_to_hr` | `approvals.view` for HR |
| `add_employee_kyc_and_documents` | `employees.kyc_*` (existing staff marked approved); `employee_documents` |
| `create_e_mandate_collections` | `loan_categories.loan_method`; mandate provider, channel, type and cancellation columns; collection batches and collections |
| `create_phone_bond_tables` | Devices and lock requests; permissions `phone_bond.manage`, `phone_bond.lock` |

All migrations add tables or columns. None deletes or rewrites existing records, except the documented backfills: `status_changed_at`, employee KYC set to approved, `loan_method` derived from `requires_mandate`, and the role permission moves.

## 3. Endpoints and permission rules

- `customers/duplicate-reviews` (list, show, release, reject; `customers.approve`). `customers/{id}/branch-transfers`, `customers/branch-transfers/{id}/approve|reject`.
- `settings/roles*` are Super Admin only. `PUT settings/employees/{id}/roles` sets additional roles. `GET auth/roles` and `POST auth/switch-role` (every request revalidates the active role).
- `POST loans/{id}/return-to-previous-stage`. `GET loans/discounts`, `POST loans/{id}/discounts`, `POST loans/discounts/{id}/approve|reject`.
- `GET loans/mandates`, `GET loans/mandates/report`, `POST loans/mandates/collections` (CSV), `POST loans/mandates/{id}/confirm-cancellation`, `POST webhooks/bank-mandate/collections` (off until configured).
- `phone-bond/devices` (list, register, show, close, lock, unlock), `phone-bond/requests/{id}/confirm`, `phone-bond/report`. Daily command `phone-bond:enforce` at 07:00.
- `GET payments/reconciliation/{id}/receipt`. `GET options/branches?transfer_targets=1`.
- `POST hrm/staff/{id}/kyc/approve|reject`, `hrm/staff/{id}/documents` (list, upload, download).
- Changed behaviour on existing endpoints:
  - `payments/cash` returns a per-tab `summary` and ignores dates on the Pending and Deposited tabs.
  - `payments/reconciliation` returns a `summary`.
  - `loans?stage=disbursement` filters on `status_changed_at`.
  - The penalty list accepts dates.
  - `crm/summary` follows the branch filter.
  - `crm/dashboard` returns `kyc_pipeline`.
  - `dashboard/credit` returns card amounts and `pipeline`.
  - `reports/statement` lists rows newest first.

Every new rule is enforced on the backend, not only hidden in the interface. A request that needs approval can't be decided by the person who made it (the existing segregation-of-duties rule, which Super Admin is exempt from).

## 4. Tests

- **Backend (PHPUnit, MySQL):** 959 tests, 942 passing. The only failures are the 17 `HistoricalFileReportTest`/`HistoricalPenaltyReportTest` tests, which already failed on the untouched baseline: their import commands exit with an error, apparently because their source data isn't in this copy of the code.
- **New backend test files:**
  - `DuplicateCustomerPreventionTest`
  - `CustomerBranchTransferTest`
  - `RoleRestrictionsAndSwitchingTest`
  - `LoanStartDateAndStageReturnTest`
  - `DiscountReceiptAndVerificationTest`
  - `EMandateCollectionsTest`
  - `PhoneBondTest`
  - `EmployeeKycAndDocumentsTest`
  - new cases in `CreditDashboardApiTest`, `CustomerCareApiTest` and `LiveReportsApiTest`
- **Frontend:** `tsc --noEmit` and ESLint report nothing. Vitest: 146 of 147 pass. The remaining failure is in the Settings menu-order test, and it fails the same way on the baseline. Two suites can't load because they import `../api/...`, which only exists in the original `api/` + `web/` folder layout.
- **Existing tests updated because the document changed the expected behaviour:**
  - HR can no longer open Settings → Roles.
  - HR can read pending approvals.
  - The statement lists the newest row first.
  - Registering two customers with the same three names is now held for verification, so the shared customer fixture gives each test customer a unique middle name.
  - The portfolio summary has two new amount fields.

## 5. Integrations and remaining dependencies

| Integration | State | Needed to finish |
|---|---|---|
| E-Mandate creation and OTP | Unchanged; the server's existing driver | — |
| E-Mandate collections | Working from bank collection files (CSV) uploaded by Finance; idempotent; Full/Partial based on the amount allocated | The bank's notification format and secret, to turn on `webhooks/bank-mandate/collections` (`integrations.bank_mandate.collection_fields`, `webhook_secret`, `company_id`) |
| E-Mandate cancellation | Requested automatically when a top-up is approved by Credit; confirmed only with the bank's reference | The bank's cancellation API, if one exists |
| Linda (Phone Bond) | Devices, statuses, the arrears plus grace-day rule, lock/unlock requests, history and reports all work. The `none` driver refuses every call, so nothing is reported as locked or unlocked without confirmation; staff can record Linda's portal reference | Linda's API documentation and credentials, to write an HTTP driver for `LindaGateway` (lock, unlock, update debt). No endpoint has been guessed |
| Outbound calls (§9.2) | **Not built.** There is no telephony provider in the system, and §9.2 asks for the integration to be documented rather than shipping a button that doesn't work | A provider with click-to-call (for example Africa's Talking Voice or Twilio Voice), an account and caller ID. Then add a `TelephonyGateway`, a call log table and the Call button |

## 6. Deployment

1. Back up the production database.
2. Merge the branches in the order in section 1, then deploy the API and the web app together.
3. Run `php artisan migrate --force`.
4. Make sure the scheduler runs, so the new `phone-bond:enforce` command executes daily.
5. Leave `LINDA_DRIVER=none` until Linda is integrated.
6. Optional: the bank-mandate webhook settings in `.env` and `config/integrations.php`, once the bank's format is known.
7. Clear caches (`php artisan optimize:clear`). Rebuild the web app (`npm run build`).
8. Tell staff about the new behaviour:
   - Register Branch Expenses is gone from the Expenses menu.
   - Reports open on the current month.
   - Lists open on today.

## 7. Rollback

- **Code:** redeploy the previous `main`.
- **Database:** `php artisan migrate:rollback --step=12` reverses the twelve migrations in reverse order. Every migration has a `down()`.
  - Data created by the new features is removed with it: duplicate reviews, transfers, discounts, collections, devices and documents. Back it up first if it must be kept.
  - Journal entries already posted are not reversed automatically: branch-transfer reclassifications and approved discounts. Reverse them through Accounting → Journals if needed.
- **Files:** uploaded receipts and documents stay on the private disk (`storage/app/private`) and can be removed separately.

## 8. Items that need a decision, or are incomplete

- **Branch-transfer accounting (§2.3).** Approval moves the customer's outstanding principal, accrued penalties and savings from Branch A's ledger to Branch B's (Dr B / Cr A), so later repayments land in the right branch. **The accountant should approve this entry.**
- **Discount account (§6).** A new expense account, 5210 LOAN DISCOUNT / WAIVER EXPENSE. The system has no account-mapping settings, so the account is defined in code next to Write-off. A discount covers outstanding interest, then insurance, then principal. Only the principal part is posted (interest and insurance are booked when collected). Penalties keep their own waiver. **The accountant should confirm the account and the order.**
- **Start Date (§3.2).**
  - If a loan is disbursed after its Start Date has passed, the schedule starts one period after disbursement, and this is recorded on the loan's history.
  - "Monthly" stays +30 days, the existing product rule.
  - The backend still accepts loans without a Start Date, for older clients. The form requires it.
- **Loan pipeline stages (§11.4).** How statuses map to the seven cards:
  - New Applications: pending manager approval.
  - Under Review: returned, or at the mandate step.
  - Credit Assessment: credit review.
  - Pending Approval: pending Finance.
  - Approved: disbursed this month.
  - Ready for Disbursement: awaiting disbursement, failed, escalated or in suspense.
  - Rejected: rejected or cancelled.
  - **Please confirm this mapping.**
- **Active Loans (§7.3).** Read as "the loans active today" (the current portfolio), with a branch filter and no date picker.
- **KYC re-approval (§2.2).** A change to an approved profile waits in the existing approval queue and the customer shows "Changes pending approval". The customer's approval status is not reset, because that would block loans in progress.
- **Reference screenshots (§11.5, §17).** These were not provided. The CRM section follows the four items in the text, and the Finance icons were removed from the summary cards only.
- **"Repayment → Bank Reconciliations" (§8.4).** This menu doesn't exist. View Receipt was added to Payments → Bank Reconciliation, and to the teller's own deposit list.
- **Not implemented:** outbound calling (§9.2, see section 5). Linda and bank-webhook connectivity wait on the providers' documentation.
