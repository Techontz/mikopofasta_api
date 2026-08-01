# MikopoFasta ERP — User Acceptance Testing Plan

**Version 1.0 · 1 August 2026**

Backend `mikopofasta_api` @ `3840471` · Frontend `mikopofasta_web` @ `a07f78f`

---

## Document control

| Field | Value |
| --- | --- |
| Document | User Acceptance Testing Plan & Result Log |
| System | MikopoFasta ERP (Microfinance Core Banking) |
| Version | 1.0 |
| Prepared | 1 August 2026 |
| Test cases | 327 across 19 sections |
| Status | Issued for execution |

### Sign-off

Testing is complete when every **Priority 1** case has passed and every failure has
either been resolved and re-tested, or accepted in writing as a known issue.

| Role | Name | Signature | Date |
| --- | --- | --- | --- |
| UAT Lead | | | |
| Operations Manager | | | |
| Finance Manager | | | |
| Head of Credit | | | |
| HR Manager | | | |
| IT / Systems | | | |
| Managing Director (final acceptance) | | | |

### Revision history

| Version | Date | Author | Change |
| --- | --- | --- | --- |
| 1.0 | 2026-08-01 | Implementation team | Initial issue |

---

## 1. Purpose and scope

This document is the acceptance test pack for MikopoFasta ERP. It exists so the
business can confirm, case by case, that the system does what the operation
needs before real customer money passes through it.

### In scope

Every built module: Authentication and Access Control, Administration and
Organization, Customer Management, Groups, Loan Origination, Repayments and
Collections, Penalties and Fees, Ledger and Journal, Treasury and Bank, Capital
and Float, Headquarters, Expenses, HR and Payroll, Salary Advance, Teller,
Reports, plus cross-cutting Branch Scope, Separation of Duties and
non-functional behaviour.

### Explicitly out of scope

| Area | Reason |
| --- | --- |
| **Agent** (`/agent/*`) | No backend module was specified or built. Screens display legacy reference data only. |
| **Insurance** (`/insurance/*`) | As above. |
| **VISA** (`/visa`) | As above. |

These three must **not** be signed off as working. Section 19 contains cases
that confirm they are correctly identified as unavailable, so the sign-off
record shows they were considered and excluded rather than overlooked.

### Known gaps carried into UAT

The following are **known and accepted** for this cycle. Section 19 tests that
each behaves as documented rather than failing unpredictably.

1. Bank reconciliation endpoint not built — no payment reaches `confirmed`.
2. Month-end close and profit posting not built.
3. Write-off and recovery ledger postings not built.
4. Dividends not built.
5. Notification delivery not built (templates exist; nothing sends them).
6. Four external integrations simulated: NIDA, bank e-mandate, Vodacom KYC, Vodacom disbursement push.

---

## 2. Test environment

| Item | Value |
| --- | --- |
| Frontend URL | `https://<uat-host>` (login page is the entry point) |
| API base | `https://<uat-api-host>/api/v1` |
| Database | UAT instance, seeded via `php artisan migrate:fresh --seed` |
| Timezone | Africa/Dar_es_Salaam — **all dates and cut-offs depend on this** |
| Currency | TZS, displayed to 2 decimal places |
| Browser | Chrome or Edge, current version, 1366×768 minimum |

**Reset procedure.** Where a case says *"reset required"*, the UAT Lead re-runs
`php artisan migrate:fresh --seed` before that case. Do not reset mid-sequence
in Sections 5–7, which build on each other.

### Test accounts

Login is by **phone number**, not email. All seeded accounts use the password
`password`, which must be changed or the accounts deleted before production.

| Phone | Name | Role | Branch |
| --- | --- | --- | --- |
| 0754000001 | Amina Juma | Super Admin | Head Office |
| 0754000002 | Baraka Mushi | Admin | Head Office |
| 0754000003 | Catherine Massawe | Finance | Head Office |
| 0754000004 | Daniel Kessy | Branch Manager | Kakonko |
| 0754000005 | Esther Mollel | Loan Officer | Kakonko |
| 0754000006 | Frank Urio | Credit Officer | Missenyi |
| 0754000007 | Grace Mbwana | HR | Head Office |
| 0754000008 | Hamisi Ally | Zone Manager | Kakonko |
| 0754000009 | Irene Komba | Regional Manager | Missenyi |
| 0754000010 | Joseph Mrema | Teller | NEW KALENGE |
| 0754000011 | Khadija Ramadhani | Auditor | Head Office |

### Seeded reference data

| Type | Values |
| --- | --- |
| Branches | Head Office, NEW KALENGE, Missenyi, Kakonko |
| Loan products | Boda Boda Working Capital, Entrepreneur Growth Loan, Salary Advance E-Mandate, Public Servant Loan, Group Solidarity Loan |
| Customer categories | BODA, SME_SMALL, SME_MEDIUM, PUBLIC_SERVANT, PRIVATE_SECTOR |
| Repayment schedules | Daily, Weekly, Monthly, Group |
| Customers | 42 |
| Groups | 1 |
| Loans | 10 (3 disbursed) |
| Trial balance after seed | 241,786,689.09 debit = 241,786,689.09 credit |

### Simulated integration values

| Integration | Test value |
| --- | --- |
| NIDA OTP | `123456` |
| Bank e-mandate OTP | `654321` |
| Vodacom KYC | Result supplied by the tester, not fetched |
| Vodacom disbursement | Settled via the inbound webhook; no outbound call is made |

---

## 3. How to execute and record

1. Work through each section **in order**. Cases within Sections 5, 6 and 7
   depend on records created by earlier cases in the same section.
2. Perform the steps exactly as written. Do not substitute values.
3. Record what you actually saw in **Actual Result** — the observed behaviour,
   not a repeat of the expectation.
4. Mark **Pass** only when the actual result matches the expected result
   completely. A partial match is a **Fail**.
5. On a Fail, record the exact on-screen message, the URL, the time, and the
   account used, in **Notes**. Attach a screenshot named `<Test Case ID>.png`.
6. Initial **Tester** and enter the **Date** on every case attempted, including
   failures and blocked cases.
7. Mark a case **Blocked** in Pass/Fail if a prior failure prevents execution,
   and name the blocking case ID in Notes.

### Priority

| Priority | Meaning |
| --- | --- |
| **P1** | Must pass before go-live. Money, access control, or data integrity. |
| **P2** | Should pass. Operationally important but has a manual workaround. |
| **P3** | Cosmetic or convenience. |

### Defect severity

| Severity | Definition | Response |
| --- | --- | --- |
| **S1 Critical** | Money is wrong, lost, or double-counted; ledger does not balance; access control bypassed. | Stop testing that module. Fix before UAT continues. |
| **S2 Major** | A workflow cannot be completed and has no workaround. | Fix before go-live. |
| **S3 Minor** | A workflow completes but behaves incorrectly in a recoverable way. | Fix or accept in writing. |
| **S4 Cosmetic** | Wording, layout, alignment. | Log for a later release. |

---

## 4. Section 1 — Authentication and Access Control

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-AUTH-001 | Authentication | P1 | System reachable; no active session | 1. Open the frontend URL<br>2. Observe where you land | Redirected to `/login`. Login form shows a phone field and a password field. No dashboard content is visible. | | | | | |
| UAT-AUTH-002 | Authentication | P1 | On `/login` | 1. Enter phone `0754000001`<br>2. Enter password `password`<br>3. Submit | Login succeeds. Redirected to `/dashboard`. The signed-in user's name (Amina Juma) appears in the header. | | | | | |
| UAT-AUTH-003 | Authentication | P1 | On `/login` | 1. Enter phone `0754000001`<br>2. Enter password `wrongpassword`<br>3. Submit | Login is refused with an invalid-credentials message. The message does **not** reveal whether the phone number exists. Still on `/login`. | | | | | |
| UAT-AUTH-004 | Authentication | P1 | On `/login` | 1. Enter phone `0700000000` (not registered)<br>2. Enter any password<br>3. Submit | Refused with the same message as UAT-AUTH-003. No distinction between an unknown account and a wrong password. | | | | | |
| UAT-AUTH-005 | Authentication | P2 | On `/login` | 1. Leave both fields empty<br>2. Submit | Field-level validation appears on both fields. No request is sent. | | | | | |
| UAT-AUTH-006 | Authentication | P1 | On `/login` | 1. Submit wrong credentials for the same phone repeatedly (6+ times in under a minute) | After the configured attempt limit the system responds with a too-many-attempts message and refuses further attempts for a cooling-off period. | | | | | |
| UAT-AUTH-007 | Authentication | P1 | Logged in as any user | 1. Open the account menu<br>2. Select Log out | Session ends. Redirected to `/login`. Pressing the browser Back button does **not** restore the dashboard. | | | | | |
| UAT-AUTH-008 | Authentication | P1 | Logged out | 1. Paste `/dashboard` directly into the address bar<br>2. Press Enter | Redirected to `/login`. No dashboard data is rendered, not even briefly. | | | | | |
| UAT-AUTH-009 | Authentication | P1 | Logged out | 1. Paste `/treasury/transactions` into the address bar | Redirected to `/login`. Repeat for `/hr/payroll` and `/admin/users` — same result. | | | | | |
| UAT-AUTH-010 | Authentication | P1 | Logged in as Amina Juma (0754000001) | 1. Navigate to `/login` directly | Redirected away from the login page to `/dashboard`. An authenticated user cannot reach the login form. | | | | | |
| UAT-AUTH-011 | Access Control | P1 | Logged in as Joseph Mrema (0754000010, Teller) | 1. Navigate directly to `/admin/users` | Access is refused. The Access Denied screen appears. No user list is shown. | | | | | |
| UAT-AUTH-012 | Access Control | P1 | Logged in as Joseph Mrema (Teller) | 1. Navigate directly to `/hr/payroll`<br>2. Then `/treasury/transactions`<br>3. Then `/admin/organization` | Access is refused on all three. Access Denied each time. | | | | | |
| UAT-AUTH-013 | Access Control | P1 | Logged in as Esther Mollel (0754000005, Loan Officer) | 1. Navigate to `/ledger` or `/treasury`<br>2. Observe | Access is refused — a Loan Officer holds no `ledger.view` or `treasury.view` permission. | | | | | |
| UAT-AUTH-014 | Access Control | P2 | Logged in as Joseph Mrema (Teller) | 1. Inspect the left navigation | Only menu items the Teller may use are listed. Administration, HR, Treasury and Capital are absent — not shown-and-disabled. | | | | | |
| UAT-AUTH-015 | Access Control | P1 | Logged in as Khadija Ramadhani (0754000011, Auditor) | 1. Open `/customers`<br>2. Attempt to create a new customer<br>3. Open `/loans` and attempt to approve a loan | Both lists are readable. No create or approve action is available. The Auditor holds view permissions only, plus `audit.view`. | | | | | |
| UAT-AUTH-016 | Access Control | P1 | Logged in as Khadija Ramadhani (Auditor) | 1. Navigate to `/admin/audit-logs` | The audit log is readable. Entries show actor, action, timestamp and affected record. | | | | | |
| UAT-AUTH-017 | Authentication | P2 | Logged in as any user | 1. Open the profile or account settings<br>2. Change the password to a value under 8 characters<br>3. Submit | Rejected with a password-strength message. The password is unchanged. | | | | | |
| UAT-AUTH-018 | Authentication | P1 | Logged in as Baraka Mushi (0754000002, Admin) | 1. Change your own password to a valid new value<br>2. Log out<br>3. Log in with the old password<br>4. Log in with the new password | The old password is refused. The new password succeeds. | | | | | |

---

## 5. Section 2 — Administration and Organization

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-ADM-001 | Administration | P2 | Logged in as Amina Juma (Super Admin) | 1. Navigate to `/admin` | The administration index lists every admin section as a link. No data of its own is shown. | | | | | |
| UAT-ADM-002 | Organization | P1 | Super Admin; on `/admin/organization` | 1. Review the company profile — legal name, trading name, registration number, TIN, phone, email, address<br>2. Confirm each against the business's registration documents | Every field matches the real company record. | | | | | |
| UAT-ADM-003 | Organization | P1 | Super Admin; on `/admin/organization` | 1. Change the trading name<br>2. Save<br>3. Reload the page | The change persists. A confirmation is shown. | | | | | |
| UAT-ADM-004 | Organization | P1 | Super Admin; on `/admin/organization` | 1. Set the Headquarters Branch to a named branch<br>2. Save<br>3. Reload | The selection persists. **Note for the business:** this field now determines where company float is drawn from and where capital is booked. Confirm the branch chosen is correct. | | | | | |
| UAT-ADM-005 | Organization | P1 | Super Admin; on `/admin/organization` | 1. Create a new branch: name "UAT Test Branch", assign a region and zone<br>2. Save | The branch is created and appears in the branch list and in branch dropdowns elsewhere. | | | | | |
| UAT-ADM-006 | Organization | P1 | UAT-ADM-005 passed | 1. Attempt to delete the branch flagged as Head Office | Refused. An error indicates the head office is protected and cannot be removed. | | | | | |
| UAT-ADM-007 | Organization | P2 | Super Admin; branch hierarchy visible | 1. Attempt to set a branch's parent to one of its own descendants | Refused with a hierarchy-cycle error. The hierarchy is unchanged. | | | | | |
| UAT-ADM-008 | Organization | P2 | Super Admin; on `/admin/organization` | 1. Create a Region<br>2. Create a Zone within it<br>3. Assign a zone manager | All three save. The zone appears under the correct region with the named manager. | | | | | |
| UAT-ADM-009 | Organization | P2 | Super Admin | 1. Attempt to delete a region that has branches assigned | Refused — the resource is in use. The region and its branches are unchanged. | | | | | |
| UAT-ADM-010 | User Management | P1 | Super Admin; on `/admin/users` | 1. Review the user list | All 11 seeded users are listed with name, phone, role, branch and status. | | | | | |
| UAT-ADM-011 | User Management | P1 | Super Admin; on `/admin/users` | 1. Create a user: name, phone `0755999001`, email, role Loan Officer, branch Kakonko, password<br>2. Save<br>3. Log out and log in as the new user | The user is created. Login succeeds. The new user sees only Loan Officer functions. | | | | | |
| UAT-ADM-012 | User Management | P1 | Super Admin; UAT-ADM-011 passed | 1. Attempt to create a second user with phone `0755999001` | Refused — the phone number is already registered. No duplicate is created. | | | | | |
| UAT-ADM-013 | User Management | P1 | Super Admin; on `/admin/users` | 1. Open the user created in UAT-ADM-011<br>2. Change the role to Branch Manager<br>3. Save<br>4. Log in as that user | The role change persists. The user now sees Branch Manager functions including loan approval. | | | | | |
| UAT-ADM-014 | User Management | P1 | Super Admin; on `/admin/users` | 1. Set the user from UAT-ADM-011 to Inactive<br>2. Save<br>3. Attempt to log in as that user | Login is refused. The account status prevents access. | | | | | |
| UAT-ADM-015 | User Management | P1 | Logged in as Amina Juma (Super Admin) | 1. Open your own user record<br>2. Attempt to deactivate or delete it | Refused — an account cannot modify its own status. This prevents locking every administrator out. | | | | | |
| UAT-ADM-016 | Roles | P1 | Super Admin; on `/admin/roles` | 1. Review the role list | 11 roles listed. Each shows its permission set. Super Admin holds all 29 permissions. | | | | | |
| UAT-ADM-017 | Roles | P1 | Super Admin; on `/admin/roles` | 1. Open Loan Officer<br>2. Confirm it holds `customers.view`, `customers.manage`, `loans.view`, `loans.create`, `reports.view` and nothing else | The permission set matches exactly. In particular it does **not** hold `loans.approve`. | | | | | |
| UAT-ADM-018 | Roles | P1 | Super Admin; on `/admin/roles` | 1. Remove `loans.create` from Loan Officer<br>2. Save<br>3. Log in as Esther Mollel and attempt to start a loan application<br>4. Restore the permission afterwards | The application action is unavailable while the permission is removed, and returns when restored. | | | | | |
| UAT-ADM-019 | Loan Products | P1 | Super Admin; on `/admin/loan-products` | 1. Review all 5 products<br>2. For each, confirm min/max amount, min/max tenure, interest rate, penalty rate, interest formula and allowed repayment schedules against the approved price list | Every parameter matches the price list. **Any mismatch is S1** — these values price every loan. | | | | | |
| UAT-ADM-020 | Loan Products | P1 | Super Admin; on `/admin/loan-products` | 1. Create a product: name "UAT Product", min 100,000, max 500,000, tenure 30–90 days, an interest formula, at least one repayment schedule<br>2. Save | The product is created and appears in the loan application product dropdown. | | | | | |
| UAT-ADM-021 | Loan Products | P2 | UAT-ADM-020 passed | 1. Set "UAT Product" to Inactive<br>2. Attempt a loan application against it | The product is refused with `PRODUCT_INACTIVE`. It no longer appears as a selectable option. | | | | | |
| UAT-ADM-022 | Interest Formulas | P1 | Super Admin; on `/admin/interest-formulas` | 1. Review each formula (Flat, Reducing, and any others)<br>2. Confirm the definition against the business's documented method | Definitions match. **Raise OSC-3 with the business here** — the rate is applied per installment, not per annum. Confirm this is the intended pricing. | | | | | |
| UAT-ADM-023 | Repayment Schedules | P1 | Super Admin; on `/admin/repayment-schedules` | 1. Review Daily, Weekly, Monthly, Group<br>2. Confirm the frequency in days for each | Frequencies match the business's collection cycles. | | | | | |
| UAT-ADM-024 | Customer Categories | P1 | Super Admin; on `/admin/customer-categories` | 1. Review all 5 categories<br>2. For each, review the eligibility rules linking it to products and any per-category maximum amount | Rules match the credit policy. | | | | | |
| UAT-ADM-025 | Loan Fees | P1 | Super Admin; on `/admin/loan-fees` | 1. Review every configured fee — name, type (percentage or flat), value, and whether deducted at disbursement | Fees match the approved fee schedule. | | | | | |
| UAT-ADM-026 | Penalty Settings | P1 | Super Admin; on `/admin/penalty` | 1. Review the penalty configuration — rate, grace period, calculation basis | Settings match the approved policy. **Raise OSC-4 here** — the penalty base includes accrued penalty, so a repeated same-day run compounds slightly. Confirm intent. | | | | | |
| UAT-ADM-027 | Reserve Setting | P2 | Super Admin; on `/admin/reserve-setting` | 1. Review the reserve configuration<br>2. Amend and save | Values persist after reload. | | | | | |
| UAT-ADM-028 | Expense Categories | P2 | Super Admin; on `/admin/expense-categories` | 1. Review categories<br>2. Create a new category mapped to a chart account<br>3. Save | Created. It appears in the expense request category dropdown. | | | | | |
| UAT-ADM-029 | Notification Templates | P2 | Super Admin; on `/admin/notification-templates` | 1. Review the 8 seeded Swahili SMS templates<br>2. Confirm the wording of each with the business | Wording approved. **Note:** nothing sends these yet — see UAT-GAP-005. | | | | | |
| UAT-ADM-030 | Notification Templates | P2 | Super Admin | 1. Attempt to create a second **active** template for a trigger event that already has one | Refused — only one active template per event is permitted. | | | | | |
| UAT-ADM-031 | Audit Log | P1 | Super Admin; several of the above cases completed | 1. Navigate to `/admin/audit-logs`<br>2. Locate the entries for UAT-ADM-003 and UAT-ADM-013 | Both changes are logged with the acting user, the action, the timestamp, and the before/after values. | | | | | |
| UAT-ADM-032 | Audit Log | P2 | On `/admin/audit-logs` | 1. Filter by user<br>2. Filter by action type<br>3. Filter by date range<br>4. Search | Each filter narrows the list correctly. Combined filters apply together. | | | | | |

---

## 6. Section 3 — Customer Management

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-CUS-001 | Customers | P1 | Logged in as Esther Mollel (Loan Officer, Kakonko) | 1. Navigate to `/customers` | The customer list renders with name, customer number, phone, branch, category and status. Only Kakonko customers are visible. | | | | | |
| UAT-CUS-002 | Customers | P1 | On `/customers` as Amina Juma (Super Admin) | 1. Observe the list | All 42 customers across all branches are visible — Super Admin holds `branches.view_all`. | | | | | |
| UAT-CUS-003 | Customers | P2 | On `/customers` | 1. Type a customer's name into the search box<br>2. Then search by customer number<br>3. Then by phone number | Each search returns the matching customer. Clearing the search restores the full list. | | | | | |
| UAT-CUS-004 | Customers | P2 | On `/customers` | 1. Apply the Branch filter<br>2. Apply the Gender filter<br>3. Apply both together<br>4. Clear | Filters narrow the list correctly and combine. The branch options list every branch that actually holds customers. | | | | | |
| UAT-CUS-005 | Customers | P2 | On `/customers` | 1. Sort by name ascending, then descending<br>2. Sort by customer number<br>3. Page forward and back | Sorting and paging behave correctly. The row count is consistent. | | | | | |
| UAT-CUS-006 | Customers | P2 | On `/customers` | 1. Export the list to CSV<br>2. Open the file | The export downloads and contains the rows currently filtered, with readable column headers. | | | | | |
| UAT-CUS-007 | Registration | P1 | Loan Officer; on `/customers/new/register` | 1. Observe the wizard | Seven steps are shown: Personal Details, Contact Details, Address, Employment/Business, Guarantors, Next of Kin, Review & Submit. | | | | | |
| UAT-CUS-008 | Registration | P1 | On step 1 of the wizard | 1. Enter a NIDA number<br>2. Trigger the NIDA lookup | Name, date of birth and gender are populated automatically from the lookup and are **not editable**. §9 requires identity data to come from NIDA, never typed. | | | | | |
| UAT-CUS-009 | Registration | P1 | UAT-CUS-008 completed | 1. Enter the NIDA OTP `123456`<br>2. Confirm | Verification succeeds and the wizard advances. | | | | | |
| UAT-CUS-010 | Registration | P1 | At the NIDA OTP step | 1. Enter an incorrect OTP such as `000000` | Refused with an invalid-OTP message. The wizard does not advance. Repeated wrong attempts eventually report attempts exceeded. | | | | | |
| UAT-CUS-011 | Registration | P1 | On the Contact Details step | 1. Attempt to continue with the phone field empty<br>2. Then enter a malformed phone number | Validation blocks both. A clear message names the field. | | | | | |
| UAT-CUS-012 | Registration | P1 | On the Address step | 1. Select Region<br>2. Select District<br>3. Select Ward<br>4. Select Street | Each dropdown is filtered by the previous selection. Selecting a different region resets the dependent fields. | | | | | |
| UAT-CUS-013 | Registration | P1 | On the Employment/Business step, category PUBLIC_SERVANT | 1. Observe the fields presented | Category-specific fields appear — employer name, check number, account number. Selecting a different category presents different fields. | | | | | |
| UAT-CUS-014 | Registration | P1 | On the Guarantors step | 1. Attempt to continue without adding a guarantor | Blocked. At least one guarantor is required before a loan can later be submitted. | | | | | |
| UAT-CUS-015 | Registration | P1 | On the Guarantors step | 1. Add a guarantor with full details<br>2. Add a second guarantor<br>3. Remove the second | Both add correctly; removal works; the first remains. | | | | | |
| UAT-CUS-016 | Registration | P1 | On the Next of Kin step | 1. Add a next of kin with name, relationship and phone | Saved and shown in the review step. | | | | | |
| UAT-CUS-017 | Registration | P1 | On the Review step | 1. Review every captured value<br>2. Submit | The customer is created. A customer number is issued. The customer appears in `/customers` with approval status Pending. | | | | | |
| UAT-CUS-018 | Registration | P2 | Mid-wizard, several steps completed | 1. Refresh the browser<br>2. Return to the wizard | The draft is retained and the wizard resumes where it was left. | | | | | |
| UAT-CUS-019 | Registration | P1 | UAT-CUS-017 passed | 1. Attempt to register a second customer with the same NIDA number | Refused — the customer is already registered. No duplicate record is created. | | | | | |
| UAT-CUS-020 | KYC | P1 | Customer from UAT-CUS-017 exists | 1. Open the customer record<br>2. Review the KYC status | Status reflects what has been captured. Documents and verification steps outstanding are visible. | | | | | |
| UAT-CUS-021 | KYC | P1 | On a customer record | 1. Upload an identity document<br>2. Upload a photograph<br>3. Save | Documents attach to the customer and are listed with type and upload date. | | | | | |
| UAT-CUS-022 | KYC | P1 | Documents uploaded | 1. Complete the KYC steps until status reads Completed | KYC status changes to Completed. This is a precondition for any loan. | | | | | |
| UAT-CUS-023 | Approval | P1 | Logged in as Daniel Kessy (Branch Manager, Kakonko); a pending Kakonko customer exists | 1. Open the customer<br>2. Approve the registration | Approval status changes to Approved. The action is recorded in the audit log with the approver's name. | | | | | |
| UAT-CUS-024 | Approval | P1 | A pending customer exists | 1. Open the customer<br>2. Reject the registration with a stated reason | Status changes to Rejected. The reason is stored and displayed. A rejected customer cannot take a loan. | | | | | |
| UAT-CUS-025 | Approval | P1 | Logged in as Esther Mollel (Loan Officer) | 1. Open a pending customer<br>2. Look for an approve action | No approve action is available. A Loan Officer holds `customers.manage` but not `customers.approve`. | | | | | |
| UAT-CUS-026 | Customer Status | P1 | An approved, active customer exists | 1. Open the customer<br>2. Freeze the account with a reason<br>3. Save | Status becomes Frozen. The reason is recorded. | | | | | |
| UAT-CUS-027 | Customer Status | P1 | UAT-CUS-026 passed | 1. Attempt to start a loan application for the frozen customer | Refused with `CUSTOMER_FROZEN`. The reason is shown to the officer. | | | | | |
| UAT-CUS-028 | Customer Status | P1 | A frozen customer exists | 1. Unfreeze the account<br>2. Attempt a loan application again | The freeze is lifted; the application may proceed (subject to the other gates). | | | | | |
| UAT-CUS-029 | Customer Status | P1 | An active customer exists | 1. Suspend the account<br>2. Attempt a loan application | Refused with `CUSTOMER_SUSPENDED`. | | | | | |
| UAT-CUS-030 | Customer Profile | P1 | On `/customers/{id}` for a customer with loans | 1. Review the profile | Identity, contact, address, category, KYC status, approval status, guarantors, next of kin, documents, notes, group membership and loan history are all present and read from live data. | | | | | |
| UAT-CUS-031 | Customer Profile | P2 | On a customer profile | 1. Add a note<br>2. Save<br>3. Reload | The note persists with the author's name and timestamp. | | | | | |
| UAT-CUS-032 | Customer Profile | P2 | On a customer profile | 1. Edit the phone number<br>2. Save | The change persists and is recorded in the audit log. | | | | | |
| UAT-CUS-033 | Customer Profile | P1 | On a customer profile | 1. Attempt to edit the name, date of birth or gender | These fields are not editable — they come from NIDA. | | | | | |
| UAT-CUS-034 | Categories | P1 | On a customer profile | 1. Change the customer's category from BODA to SME_SMALL<br>2. Save | The change persists. The products the customer is eligible for change accordingly. | | | | | |
| UAT-CUS-035 | Customers | P2 | Logged in as any user with `customers.view` | 1. Navigate to `/customers/overview` | Summary tiles show real counts by status and category, consistent with the customer list. | | | | | |
| UAT-CUS-036 | Customers | P2 | On `/customers/by-type/monthly` | 1. Review the list | Customers are listed by their loans' repayment schedule. The figures reconcile with the loan book. | | | | | |
| UAT-CUS-037 | Customers | P2 | On `/customers/profile` | 1. Search for a customer<br>2. Open the result | The search returns matching customers and opens the correct profile. | | | | | |

---

## 7. Section 4 — Groups

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-GRP-001 | Groups | P2 | Logged in with `customers.view`; on `/groups` | 1. Review the group list | Groups are listed with name, branch, leader, member count, outstanding balance and status. | | | | | |
| UAT-GRP-002 | Groups | P2 | On `/groups` | 1. Create a group: name "UAT Solidarity Group", branch Kakonko, meeting day and time<br>2. Save | The group is created and appears in the list. | | | | | |
| UAT-GRP-003 | Groups | P1 | UAT-GRP-002 passed | 1. Add three customers as members<br>2. Assign one as Leader, one as Secretary, one as Treasurer<br>3. Save | Members are added. The committee is shown on the group row, derived from the membership rather than stored separately. | | | | | |
| UAT-GRP-004 | Groups | P1 | UAT-GRP-003 passed | 1. Attempt to assign a second member as Leader | Refused — at most one member may hold each office. | | | | | |
| UAT-GRP-005 | Groups | P2 | A group with members exists | 1. Remove a member<br>2. Save | The member is removed. The member count decreases. If they held an office, the office reads Vacant. | | | | | |
| UAT-GRP-006 | Groups | P2 | On `/groups/overview` | 1. Review the summary | Total groups, active groups, total members and total outstanding are shown and reconcile with the group list. | | | | | |
| UAT-GRP-007 | Groups | P2 | On `/groups` | 1. Use the View Members action on a group | Navigates to the customer list filtered to that group's members. | | | | | |
| UAT-GRP-008 | Groups | P1 | Logged in as Esther Mollel (Loan Officer, Kakonko) | 1. Navigate to `/groups` | Only Kakonko groups are visible. Groups at other branches are not listed. | | | | | |

---

## 8. Section 5 — Loan Origination

> **Sequential.** UAT-LON-010 onward follow one loan through its full lifecycle.
> Do not reset the database between them.

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-LON-001 | Loans | P1 | Logged in as Esther Mollel (Loan Officer) | 1. Navigate to `/loans/new`<br>2. Select a customer | The applicant picker lists customers the officer may see. Selecting one opens the application form. | | | | | |
| UAT-LON-002 | Loans | P1 | On `/loans/new/apply` with an eligible customer | 1. Select product "Boda Boda Working Capital"<br>2. Select a repayment schedule<br>3. Enter a principal within the product limits<br>4. Enter a tenure within the product range<br>5. Run the eligibility check | The check passes. The projected schedule and total payable are displayed before submission. | | | | | |
| UAT-LON-003 | Loans | P1 | Application form open; customer KYC is **not** complete | 1. Attempt to submit | Refused with `KYC_INCOMPLETE`. The message states which gate failed. | | | | | |
| UAT-LON-004 | Loans | P1 | Customer has **no** guarantor recorded | 1. Attempt to submit an application | Refused with `GUARANTORS_REQUIRED` — at least one guarantor is required. | | | | | |
| UAT-LON-005 | Loans | P1 | Customer approval status is Pending | 1. Attempt to submit | Refused with `CUSTOMER_PENDING_APPROVAL`. | | | | | |
| UAT-LON-006 | Loans | P1 | Eligible customer; product selected | 1. Enter a principal **below** the product minimum<br>2. Attempt to submit | Refused with `AMOUNT_BELOW_MINIMUM`, naming the minimum. | | | | | |
| UAT-LON-007 | Loans | P1 | Eligible customer; product selected | 1. Enter a principal **above** the product/category maximum<br>2. Attempt to submit | Refused with `AMOUNT_ABOVE_MAXIMUM`, naming the maximum that applies to this customer's category. | | | | | |
| UAT-LON-008 | Loans | P1 | Eligible customer; product selected | 1. Enter a tenure outside the product's min/max days<br>2. Attempt to submit | Refused with `TENURE_OUT_OF_RANGE`, naming the permitted range. | | | | | |
| UAT-LON-009 | Loans | P1 | Customer in category BODA; select a product their category is not eligible for | 1. Attempt to submit | Refused with `CATEGORY_NOT_ELIGIBLE_FOR_PRODUCT`. | | | | | |
| UAT-LON-010 | Loans | P1 | Fully eligible customer at Kakonko; logged in as Esther Mollel | 1. Complete a valid application: Boda Boda Working Capital, 500,000, weekly, 90 days<br>2. Submit | The loan is created with a loan number in the form `LN-2026-NNNNNN`. Status is **Pending Manager Approval**. It appears in `/loans/pending`. | | | | | |
| UAT-LON-011 | Loans | P1 | UAT-LON-010 passed | 1. Attempt a **second** application for the same customer | Refused with `EXISTING_OPEN_LOAN`, naming the open loan number. One open loan at a time. | | | | | |
| UAT-LON-012 | Loans | P1 | Logged in as Esther Mollel (the officer who raised the loan) | 1. Open the loan from UAT-LON-010<br>2. Look for an approve action | No approve action is available. A Loan Officer holds no `loans.approve` permission — §14 separation of duties. | | | | | |
| UAT-LON-013 | Loans | P1 | Logged in as Daniel Kessy (Branch Manager, Kakonko) | 1. Navigate to `/loans/pending`<br>2. Open the loan<br>3. Review the applicant, product terms, amount and schedule<br>4. Approve | Status moves to the next state — Mandate Pending OTP if the product requires a mandate, otherwise Pending Credit Review. The transition is logged with the approver. | | | | | |
| UAT-LON-014 | Loans | P1 | A loan at Pending Manager Approval | 1. Reject it with a stated reason | Status becomes **Rejected**. The reason is stored and shown. The loan appears in `/loans/rejected`. | | | | | |
| UAT-LON-015 | Loans | P1 | UAT-LON-014 passed | 1. Attempt any further action on the rejected loan | No forward transition is available. Rejected is terminal. | | | | | |
| UAT-LON-016 | Loans | P1 | A loan on a mandate-requiring product at Mandate Pending OTP | 1. Enter the e-mandate OTP `654321`<br>2. Confirm | The mandate becomes Active. Status moves to Pending Credit Review. | | | | | |
| UAT-LON-017 | Loans | P1 | A loan at Mandate Pending OTP | 1. Enter an incorrect OTP | Refused with `INVALID_MANDATE_OTP`. Status becomes Mandate Failed after the permitted attempts. | | | | | |
| UAT-LON-018 | Loans | P2 | A loan at Mandate Failed | 1. Retry the mandate | The loan returns to Mandate Pending OTP and a new attempt is permitted. | | | | | |
| UAT-LON-019 | Loans | P1 | Logged in as Frank Urio (Credit Officer, Missenyi); loan is at Kakonko | 1. Attempt to open and review the Kakonko loan | Refused with `BRANCH_SCOPE_VIOLATION`. §13 makes the Credit Officer strictly branch-scoped unless granted `loans.review_cross_branch`. | | | | | |
| UAT-LON-020 | Loans | P1 | The loan from UAT-LON-013 sits at Kakonko and the seed has **no** Kakonko Credit Officer. As Super Admin, either create one at Kakonko or grant Frank Urio `loans.review_cross_branch`. Then log in as that officer | 1. Open the loan<br>2. Run the telco/KYC verification with a passing result | Status moves to **Pending Finance**. The verification result is recorded. | | | | | |
| UAT-LON-021 | Loans | P1 | A loan at Pending Credit Review | 1. Run the verification with a **failing** result | The loan is **Rejected** outright. §10 gives Pending Credit Review only two exits; a telco mismatch means the identity behind the phone could not be confirmed. | | | | | |
| UAT-LON-022 | Loans | P1 | Logged in as Daniel Kessy (Branch Manager); loan at Pending Finance | 1. Attempt to disburse | No disburse action is available. Only Finance holds `loans.disburse` — §16.8. | | | | | |
| UAT-LON-023 | Loans | P1 | Logged in as Catherine Massawe (Finance); loan at Pending Finance | 1. Open the loan<br>2. Prepare disbursement, selecting a channel | Status moves to **Awaiting Disbursement**. A disbursement batch is created. | | | | | |
| UAT-LON-024 | Loans | P1 | UAT-LON-023 passed | 1. Settle the disbursement (simulated provider callback)<br>2. Reopen the loan | Status becomes **Active**. A disbursement date and expected completion date are stamped. The repayment schedule is generated. | | | | | |
| UAT-LON-025 | Loans | P1 | UAT-LON-024 passed | 1. Open `/ledger` or the journal for the disbursement entry | A balanced journal entry exists: **Dr Loan Receivable / Cr Principal** (plus any fee lines). Debits equal credits. | | | | | |
| UAT-LON-026 | Loans | P1 | UAT-LON-024 passed | 1. Open the loan detail<br>2. Review the generated schedule | Every installment shows number, due date, principal due, interest due and status. The number of installments matches the tenure and cadence. The sum of principal due equals the loan principal. | | | | | |
| UAT-LON-027 | Loans | P1 | UAT-LON-024 passed; fees configured as deducted at disbursement | 1. Compare the amount disbursed to the customer against the principal | The difference equals the configured deduction. The fee appears as income in the ledger. | | | | | |
| UAT-LON-028 | Loans | P1 | A settled disbursement | 1. Re-send the same provider callback | The repeat is ignored. No second ledger entry is created and the loan is not activated twice. | | | | | |
| UAT-LON-029 | Loans | P2 | A disbursement that failed | 1. Open the loan (status Disbursement Failed)<br>2. Retry the disbursement | The loan returns to Awaiting Disbursement and may settle. Alternatively it may be escalated or cancelled. | | | | | |
| UAT-LON-030 | Loans | P1 | An active loan | 1. Attempt to move it directly to a state the §10 table forbids, e.g. prepare disbursement on a loan at Pending Manager Approval | Refused with `INVALID_LOAN_STATE`. The state machine permits only the defined transitions. | | | | | |
| UAT-LON-031 | Loans | P1 | An active loan fully repaid | 1. Close the loan | Status becomes **Closed**. Outstanding reads zero. Closed is terminal. | | | | | |
| UAT-LON-032 | Loans | P2 | A recently closed loan for a customer | 1. Attempt a new application for that customer within the cooldown window | Refused with `CUSTOMER_IN_COOLDOWN`, naming the date the cooldown ends. | | | | | |
| UAT-LON-033 | Loans | P2 | An active loan | 1. Freeze the loan<br>2. Attempt to take a payment against it | The loan is Frozen. Repayment behaviour follows the documented rule for frozen loans. | | | | | |
| UAT-LON-034 | Loans | P1 | On `/loans/book` | 1. Review the loan book | Every loan is listed with its outstanding balance. The Outstanding tile equals the sum of the listed balances. | | | | | |
| UAT-LON-035 | Loans | P1 | On `/loans/book` | 1. Pick any loan<br>2. Note the Outstanding shown in the list<br>3. Open the loan detail and note the Outstanding there | **The two figures are identical.** A list row and a detail page must never disagree about what a loan owes. | | | | | |
| UAT-LON-036 | Loans | P2 | On `/loans/pending`, `/loans/disbursed`, `/loans/rejected`, `/loans/withdrawal` | 1. Open each queue in turn | Each lists only loans in the relevant states. The tiles at the top reconcile with the rows below. | | | | | |
| UAT-LON-037 | Loans | P2 | On any loan queue | 1. Search by loan number<br>2. Search by customer name<br>3. Filter by branch and by status<br>4. Sort and page | All behave correctly and combine. | | | | | |
| UAT-LON-038 | Loans | P1 | On a loan detail page | 1. Open the status history | Every transition is listed with the from-state, to-state, the acting user, the timestamp and any reason given. | | | | | |
| UAT-LON-039 | Loans | P2 | An active loan; customer eligible for a top-up | 1. Check top-up eligibility | The eligibility result is shown with the reason if refused. | | | | | |
| UAT-LON-040 | Loans | P1 | Logged in as Esther Mollel (Loan Officer, Kakonko) | 1. Navigate to `/loans`<br>2. Review the list | Only Kakonko loans are visible. Attempting to open a Missenyi loan by URL is refused with `BRANCH_SCOPE_VIOLATION`. | | | | | |

---

## 9. Section 6 — Repayments and Collections

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-RPY-001 | Repayments | P1 | Logged in as Joseph Mrema (Teller, NEW KALENGE); an **open** loan exists at NEW KALENGE — the seed provides LN-2026-000006 and LN-2026-000009 in Arrears, both repayable | 1. Navigate to the cash payment screen<br>2. Select the loan<br>3. Enter an amount<br>4. Submit | The payment is recorded with a payment reference. A receipt is available. | | | | | |
| UAT-RPY-002 | Repayments | P1 | UAT-RPY-001 passed | 1. Open the loan's schedule | The payment has been allocated **penalty first, then interest, then principal**, oldest installment first. | | | | | |
| UAT-RPY-003 | Repayments | P1 | UAT-RPY-001 passed | 1. Open the ledger entry for the payment | A balanced journal entry exists. Teller cash is debited; the loan receivable and income accounts are credited per §5. Debits equal credits. | | | | | |
| UAT-RPY-004 | Repayments | P1 | A loan with several installments outstanding | 1. Take a payment large enough to clear the first installment exactly | The first installment is fully cleared and marked accordingly before any amount touches the second. | | | | | |
| UAT-RPY-005 | Repayments | P1 | A loan with an outstanding balance | 1. Take a payment for the **full** outstanding amount | The outstanding balance reads zero. The loan becomes eligible for closure. | | | | | |
| UAT-RPY-006 | Repayments | P1 | Note a Kakonko loan id as Super Admin first; then log in as Joseph Mrema (Teller, NEW KALENGE) | 1. Attempt to take cash against the **Kakonko** loan by URL | Refused with `BRANCH_SCOPE_VIOLATION`. A violation is written to the audit log. | | | | | |
| UAT-RPY-007 | Repayments | P1 | Logged in as Esther Mollel (Loan Officer) | 1. Attempt to take a cash payment | No cash entry action is available. Only `repayments.cash_entry` holders may take cash — Teller, Finance, Admin. | | | | | |
| UAT-RPY-008 | Repayments | P1 | Any active loan | 1. Attempt a payment of zero<br>2. Then a negative amount<br>3. Then a non-numeric value | All three are refused with validation messages. No payment is created. | | | | | |
| UAT-RPY-009 | Repayments | P1 | A cash payment taken | 1. Review its status | Status is **Pending Verification** — teller cash-in-hand is a different trust state from bank-confirmed money. It does not read Confirmed. See UAT-GAP-001. | | | | | |
| UAT-RPY-010 | Repayments | P1 | On the payments list | 1. Review the list | Payments show date, reference, loan number, channel, amount and status. | | | | | |
| UAT-RPY-011 | Repayments | P2 | On the payments list | 1. Search by payment reference<br>2. Search by loan number<br>3. Filter by status and by channel<br>4. Sort and page | All behave correctly and combine. | | | | | |
| UAT-RPY-012 | Repayments | P1 | Logged in as Catherine Massawe (Finance) | 1. Open the suspense queue | Unmatched or unallocated money is listed with the reason it could not be matched. | | | | | |
| UAT-RPY-013 | Repayments | P1 | An item in suspense | 1. Allocate it to the correct loan | The item leaves suspense. The loan schedule is updated. A balanced ledger entry is posted. | | | | | |
| UAT-RPY-014 | Repayments | P2 | An item in suspense | 1. Mark it for investigation with a note | The item is flagged and the note is stored. It remains in the queue. | | | | | |
| UAT-RPY-015 | Repayments | P1 | An allocated suspense item | 1. Attempt to allocate it a second time | Refused with `SUSPENSE_ALREADY_RESOLVED`. No second allocation or ledger entry occurs. | | | | | |
| UAT-RPY-016 | Repayments | P1 | Provider webhook available | 1. Send a payment webhook with a valid signature | The payment is accepted, matched and allocated. A balanced ledger entry is posted. | | | | | |
| UAT-RPY-017 | Repayments | P1 | Provider webhook available | 1. Send a payment webhook with an **invalid or missing** signature | Rejected with `INVALID_WEBHOOK_SIGNATURE`. No payment is created and nothing is posted. | | | | | |
| UAT-RPY-018 | Repayments | P1 | UAT-RPY-016 passed | 1. Re-send the identical webhook payload | The duplicate is rejected with `DUPLICATE_TRANSACTION`. Exactly one payment and one ledger entry exist. | | | | | |
| UAT-RPY-019 | Repayments | P1 | An active loan and a teller session | 1. Take the same cash payment twice in quick succession (double-click submit) | Only one payment is recorded. The idempotency protection prevents a duplicate. | | | | | |
| UAT-RPY-020 | Repayments | P1 | A loan that is Closed | 1. Attempt to take a payment against it | Refused with `LOAN_NOT_REPAYABLE`. | | | | | |

---

## 10. Section 7 — Penalties and Fees

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-PEN-001 | Penalties | P1 | A loan with an installment past its due date and unpaid | 1. Run the overdue process (or wait for the 00:05 scheduled run) | A penalty is calculated and applied to the overdue installment. `penalty_due` increases. | | | | | |
| UAT-PEN-002 | Penalties | P1 | UAT-PEN-001 passed | 1. Check the ledger for a penalty accrual entry | **No ledger entry is posted on accrual.** Penalty income is recognised on collection only. This is OSC-1 — confirm the business accepts collection-basis recognition. | | | | | |
| UAT-PEN-003 | Penalties | P1 | UAT-PEN-001 passed | 1. Run the overdue process a second time on the same day<br>2. Compare the penalty before and after | The penalty is **topped up to** the calculated figure, not added to it — it does not double. A small increase is expected because the base includes accrued penalty. This is OSC-4. | | | | | |
| UAT-PEN-004 | Penalties | P1 | A loan carrying an accrued penalty | 1. Take a payment covering the penalty | The penalty is cleared first. A ledger entry credits **Penalty Income**. This is the only point at which penalty reaches the books. | | | | | |
| UAT-PEN-005 | Penalties | P2 | On `/penalty/list` | 1. Review the penalty register | All accrued penalties are listed with loan, customer, amount and date. | | | | | |
| UAT-PEN-006 | Penalties | P2 | On `/penalty/paid` | 1. Review collected penalties | Only penalties actually collected are listed. The total reconciles with Penalty Income in the ledger. | | | | | |
| UAT-PEN-007 | Penalties | P1 | An active loan that has moved past its grace period | 1. Review the loan status | The loan status reflects arrears per the configured grace period. | | | | | |
| UAT-PEN-008 | Fees | P1 | On `/loan-fee/deducted-income` | 1. Review the deducted fee income | Fees deducted at disbursement are listed. The total reconciles with the fee income account in the ledger. | | | | | |
| UAT-PEN-009 | Fees | P2 | On `/penalty/list` and `/loan-fee/deducted-income` | 1. Filter each by branch<br>2. Filter by date range<br>3. Export | Filters and export behave correctly. | | | | | |
| UAT-PEN-010 | Penalties | P1 | Scheduler configured | 1. Confirm with IT that a cron runs `schedule:run` every minute<br>2. Confirm the penalty job is scheduled daily at 00:05 Africa/Dar_es_Salaam | The scheduler is running. **Without it no penalty ever accrues in production.** | | | | | |

---

## 11. Section 8 — Ledger and Journal

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-LDG-001 | Ledger | P1 | Logged in as Catherine Massawe (Finance) | 1. Navigate to the trial balance | Total debits equal total credits. On the freshly seeded database both read **241,786,689.09**. | | | | | |
| UAT-LDG-002 | Ledger | P1 | Finance; chart of accounts visible | 1. Review the chart of accounts<br>2. Confirm every account, its code, type and normal balance with the Finance Manager | The chart matches the approved accounting structure. **Any discrepancy is S1.** | | | | | |
| UAT-LDG-003 | Ledger | P1 | Finance; on the journal list | 1. Open any journal entry | The entry shows every line with account, debit, credit, branch and narration. **Debits equal credits.** | | | | | |
| UAT-LDG-004 | Ledger | P1 | Finance | 1. Open an account and view its entries<br>2. Confirm the running balance | Entries are listed chronologically. The closing balance equals the account balance shown on the trial balance. | | | | | |
| UAT-LDG-005 | Ledger | P1 | Finance; a posted journal entry | 1. Request a reversal with a stated reason | A reversal request is created in Pending state. **The original entry is unchanged** — nothing is deleted or edited. | | | | | |
| UAT-LDG-006 | Ledger | P1 | Logged in as the same user who requested the reversal | 1. Attempt to approve your own reversal request | Refused. Request and approval are separate permissions (`ledger.reverse.request` / `ledger.reverse.approve`) — §14. | | | | | |
| UAT-LDG-007 | Ledger | P1 | A pending reversal; logged in as a `ledger.reverse.approve` holder other than the requester | 1. Approve the reversal | A **new, opposite** journal entry is posted. The original remains. The trial balance still balances. | | | | | |
| UAT-LDG-008 | Ledger | P1 | UAT-LDG-007 passed | 1. Attempt to reverse the same entry again | Refused with `ENTRY_ALREADY_REVERSED`. | | | | | |
| UAT-LDG-009 | Ledger | P2 | A pending reversal | 1. Reject it with a reason | The request is rejected. No reversing entry is posted. The reason is recorded. | | | | | |
| UAT-LDG-010 | Ledger | P1 | After completing Sections 5, 6 and 7 | 1. Re-run the trial balance | **Debits still equal credits.** Every transaction posted during UAT has kept the books in balance. This is the single most important check in the pack. | | | | | |
| UAT-LDG-011 | Ledger | P2 | Finance; on the journal list | 1. Filter by date range<br>2. Filter by account<br>3. Filter by branch<br>4. Search the narration | Filters narrow correctly and combine. | | | | | |
| UAT-LDG-012 | Ledger | P1 | Logged in as Khadija Ramadhani (Auditor) | 1. Open the ledger and the trial balance<br>2. Attempt to request or approve a reversal | Both are readable. No reversal action is available — the Auditor holds `ledger.view` only. | | | | | |

---

## 12. Section 9 — Treasury and Bank

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-TRE-001 | Treasury | P1 | Logged in as Catherine Massawe (Finance); on `/treasury` | 1. Review the treasury dashboard | Cash position, bank balances and capital are shown from live ledger data. | | | | | |
| UAT-TRE-002 | Bank | P1 | Finance; on `/treasury/bank-accounts` | 1. Create a bank account: bank name, account name, account number, branch<br>2. Save | Created and listed. It appears in bank transaction and transfer dropdowns. | | | | | |
| UAT-TRE-003 | Bank | P2 | UAT-TRE-002 passed | 1. Edit the account name<br>2. Save<br>3. Reload | The change persists. | | | | | |
| UAT-TRE-004 | Bank | P1 | Finance; on `/treasury/transactions` | 1. Raise a bank transaction request — deposit into a bank account, with amount and narration<br>2. Submit | The request is created in Pending state and appears in the list. | | | | | |
| UAT-TRE-005 | Bank | P1 | Logged in as the user who raised the request in UAT-TRE-004 | 1. Attempt to approve your own request | Refused. The person deciding must not be the person asking — §14. | | | | | |
| UAT-TRE-006 | Bank | P1 | A pending bank transaction; logged in as a different `treasury.manage` holder | 1. Approve the request | Status becomes Approved. A **balanced ledger entry** is posted. The request appears in `/treasury/transactions/approved`. | | | | | |
| UAT-TRE-007 | Bank | P1 | A pending bank transaction | 1. Reject it with a reason | Status becomes Rejected. **No ledger entry is posted.** The reason is recorded. | | | | | |
| UAT-TRE-008 | Bank | P1 | An approved bank transaction | 1. Attempt to approve or reject it again | Refused — the request is no longer decidable. | | | | | |
| UAT-TRE-009 | Bank | P2 | On `/treasury/accounts` | 1. Review account balances<br>2. Compare against the ledger | Balances match the corresponding ledger accounts. | | | | | |
| UAT-TRE-010 | Bank | P1 | Finance; on `/treasury/transfers/branch` | 1. Create a branch-to-branch transfer: from branch, to branch, amount<br>2. Submit and approve | The transfer posts a balanced entry moving value between the two branches' accounts. Both branch positions change correctly. | | | | | |
| UAT-TRE-011 | Bank | P2 | On `/treasury/transfers/salary-advance` | 1. Review salary advance transfers | Transfers relating to salary advances are listed and reconcile with the advance records. | | | | | |
| UAT-TRE-012 | Treasury | P2 | On `/treasury/expenses` and `/treasury/expenses/requests` | 1. Review both screens | Expense claims and requests visible to Treasury are listed with their status. | | | | | |
| UAT-TRE-013 | Treasury | P2 | On `/treasury/payroll` | 1. Review payroll runs awaiting payment | Runs approved by HR and awaiting Finance payment are listed. | | | | | |
| UAT-TRE-014 | Bank | P2 | On `/treasury/transactions` with no records | 1. Observe the empty state | A clear empty state states the list is empty and what would put a row in it. No placeholder text such as `EMPTY_` appears. | | | | | |
| UAT-TRE-015 | Treasury | P1 | Logged in as Daniel Kessy (Branch Manager) | 1. Navigate to `/treasury` | Access is refused — a Branch Manager holds no `treasury.view`. | | | | | |

---

## 13. Section 10 — Capital and Float

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-CAP-001 | Capital | P1 | Finance; on `/capital/shareholders` | 1. Register a shareholder: full name, phone, email, gender, date of birth<br>2. Save | The shareholder is created and listed. | | | | | |
| UAT-CAP-002 | Capital | P2 | UAT-CAP-001 passed | 1. Edit the shareholder's phone number<br>2. Save | The change persists and is audited. | | | | | |
| UAT-CAP-003 | Capital | P1 | A shareholder exists; on `/capital/contributions` | 1. Record a capital contribution: shareholder, amount, date<br>2. Save | The contribution is recorded. A **balanced ledger entry** is posted crediting capital. | | | | | |
| UAT-CAP-004 | Capital | P1 | UAT-CAP-003 passed | 1. Confirm which branch the contribution was booked against | It is booked against the branch named as Headquarters on the company profile (see UAT-ADM-004), falling back to the branch flagged Head Office if none is named. **Confirm this precedence is what the business intends** — candidate OSC-8. | | | | | |
| UAT-CAP-005 | Capital | P1 | On `/capital/float` | 1. Request a company float transfer to a branch: amount, destination branch<br>2. Submit | The request is created in Pending state. | | | | | |
| UAT-CAP-006 | Capital | P1 | A pending float transfer; logged in as an approver other than the requester | 1. Approve it | Status becomes Approved. A **balanced ledger entry** moves the float. The branch's teller cash increases. | | | | | |
| UAT-CAP-007 | Capital | P1 | UAT-CAP-006 passed | 1. Confirm the source of the company float | Drawn from the branch named as Headquarters on the company profile. Same precedence question as UAT-CAP-004. | | | | | |
| UAT-CAP-008 | Capital | P1 | A pending float transfer | 1. Reject it with a reason | Rejected. **No ledger entry is posted.** | | | | | |
| UAT-CAP-009 | Capital | P1 | On `/capital/float-branch` | 1. Create a branch-to-branch float transfer<br>2. Approve it | Value moves between the two branches. Both positions change correctly and the entry balances. | | | | | |
| UAT-CAP-010 | Capital | P1 | On `/capital/float-accounts` | 1. Create an account-to-account float transfer<br>2. Approve it | Value moves between the two named accounts. The entry balances. | | | | | |
| UAT-CAP-011 | Capital | P2 | On `/capital/float-approved` | 1. Review approved float transfers | All approved transfers are listed with their journal entry reference. | | | | | |
| UAT-CAP-012 | Capital | P2 | On `/treasury/capital` | 1. Review the capital position | Total capital reconciles with the capital accounts on the trial balance. | | | | | |
| UAT-CAP-013 | Capital | P1 | Any float transfer approved during UAT | 1. Re-run the trial balance | Debits still equal credits. | | | | | |

---

## 14. Section 11 — Headquarters

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-HQ-001 | Headquarters | P1 | Finance or Admin; on `/hq/transactions/balance` | 1. Review HQ account balances | Balances are shown from live ledger data and reconcile with the trial balance. | | | | | |
| UAT-HQ-002 | Headquarters | P1 | On `/hq/transactions/requests` | 1. Raise an HQ transaction: type, amount, narration<br>2. Submit | Created in Pending state and listed. | | | | | |
| UAT-HQ-003 | Headquarters | P1 | A pending HQ transaction; approver differs from requester | 1. Approve it | Approved. A **balanced ledger entry** is posted. It moves to `/hq/transactions/approved`. | | | | | |
| UAT-HQ-004 | Headquarters | P1 | A pending HQ transaction | 1. Reject it with a reason | Rejected. No ledger entry. Reason recorded. | | | | | |
| UAT-HQ-005 | Headquarters | P1 | Logged in as the requester | 1. Attempt to approve your own HQ transaction | Refused — §14 separation of duties. | | | | | |
| UAT-HQ-006 | Headquarters | P2 | On `/hq/expenses/register` | 1. Register an HQ expense | Recorded and listed. | | | | | |
| UAT-HQ-007 | Headquarters | P2 | On `/hq/expenses/requests` and `/hq/expenses/approved` | 1. Review both queues | Requests awaiting decision and those approved are correctly separated. | | | | | |
| UAT-HQ-008 | Headquarters | P2 | On `/hq/transactions/requests` | 1. Filter by branch<br>2. Filter by status<br>3. Search | Filters behave correctly. | | | | | |

---

## 15. Section 12 — Expenses

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-EXP-001 | Expenses | P1 | Logged in as Daniel Kessy (Branch Manager); on `/expenses/register` | 1. Raise an expense request: category, amount, description, supporting detail<br>2. Submit | Created in Pending state and listed in `/expenses/requests`. | | | | | |
| UAT-EXP-002 | Expenses | P1 | UAT-EXP-001 passed; logged in as the requester | 1. Attempt to approve your own request | Refused — §14. | | | | | |
| UAT-EXP-003 | Expenses | P1 | A pending expense; logged in as an approver | 1. Approve it | Status becomes Approved. A **balanced ledger entry** debits the mapped expense account. It appears in `/expenses/approved`. | | | | | |
| UAT-EXP-004 | Expenses | P1 | A pending expense | 1. Reject it with a reason | Rejected. **No ledger entry is posted.** The reason is stored and visible to the requester. | | | | | |
| UAT-EXP-005 | Expenses | P1 | An approved expense | 1. Attempt to approve or reject it again | Refused — the request is no longer decidable. | | | | | |
| UAT-EXP-006 | Expenses | P2 | A pending expense | 1. Add a comment<br>2. Save | The comment is stored with the author and timestamp and is visible to both parties. | | | | | |
| UAT-EXP-007 | Expenses | P1 | Expense categories configured | 1. Raise an expense against a category whose chart account is inactive | Refused — the mapped account must be active. | | | | | |
| UAT-EXP-008 | Expenses | P1 | An expense request with an amount of zero or negative | 1. Attempt to submit | Refused with validation. | | | | | |
| UAT-EXP-009 | Expenses | P1 | Logged in as Esther Mollel (Loan Officer, Kakonko) | 1. Navigate to `/expenses/requests` | Only Kakonko expense requests are visible, if the role permits access at all. | | | | | |
| UAT-EXP-010 | Expenses | P2 | On `/expenses/approved` | 1. Filter by branch, category and date range<br>2. Export | Filters and export behave correctly. Totals reconcile with the expense accounts in the ledger. | | | | | |
| UAT-EXP-011 | Expenses | P1 | After approving expenses during UAT | 1. Open the Branch Expense report<br>2. Compare with the ledger | The report total reconciles with the expense-category chart accounts. | | | | | |

---

## 16. Section 13 — HR and Payroll

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-HR-001 | HR | P1 | Logged in as Grace Mbwana (0754000007, HR); on `/hr/staff` | 1. Review the staff list | Staff are listed with name, role, branch, employment status and salary where permitted. | | | | | |
| UAT-HR-002 | HR | P1 | HR; on `/hr/staff` | 1. Register a staff member: personal details, branch, role, basic salary, bank details, start date<br>2. Save | The staff profile is created and appears in the list. A linked user account is created if requested. | | | | | |
| UAT-HR-003 | HR | P1 | UAT-HR-002 passed | 1. Attempt to create a second staff profile for the same user | Refused with `STAFF_PROFILE_EXISTS`. | | | | | |
| UAT-HR-004 | HR | P1 | A staff profile exists | 1. Open it<br>2. Change the basic salary and the bank details together<br>3. Save | Both changes save together. An audit row records the change. If any part fails, none is applied. | | | | | |
| UAT-HR-005 | HR | P2 | A staff profile exists | 1. Set the staff member to inactive<br>2. Check `/hr/inactive-staff` | They appear in the inactive list and are excluded from payroll generation. | | | | | |
| UAT-HR-006 | HR | P1 | HR; on a staff profile | 1. Add an allowance: type, amount, recurring or one-off<br>2. Save | The allowance is recorded and will be included in the next payroll run. | | | | | |
| UAT-HR-007 | HR | P1 | HR; on a staff profile | 1. Add a deduction: type, amount<br>2. Save | The deduction is recorded and will be applied in the next payroll run. | | | | | |
| UAT-HR-008 | Payroll | P1 | HR; on `/hr/payroll` | 1. Generate a payroll run for a period not yet run<br>2. Submit | The run is created in Draft/Generated state. Every active staff member appears with gross, allowances, deductions and net. | | | | | |
| UAT-HR-009 | Payroll | P1 | UAT-HR-008 passed | 1. Attempt to generate a run for the **same** period again | Refused with `PAYROLL_PERIOD_EXISTS`. No duplicate run is created. | | | | | |
| UAT-HR-010 | Payroll | P1 | A generated payroll run | 1. Open it<br>2. For one staff member, verify: net = basic + allowances − deductions − any staff loan or advance recovery | The arithmetic is correct for every line checked. **Any error is S1.** | | | | | |
| UAT-HR-011 | Payroll | P1 | A generated run; logged in as Catherine Massawe (Finance) | 1. Attempt to approve the run | Refused — **HR approves** (§16.7). Finance's role comes later. | | | | | |
| UAT-HR-012 | Payroll | P1 | A generated run; logged in as Grace Mbwana (HR) | 1. Approve the run | Status moves to Approved. The approver is recorded. | | | | | |
| UAT-HR-013 | Payroll | P1 | An approved run; logged in as Grace Mbwana (HR) | 1. Attempt to pay the run | Refused — **Finance disburses** (§16.8). HR cannot both approve and pay. | | | | | |
| UAT-HR-014 | Payroll | P1 | An approved run; logged in as Catherine Massawe (Finance) | 1. Finalize, then pay the run | The run is paid. A **balanced ledger entry** is posted for the payroll. | | | | | |
| UAT-HR-015 | Payroll | P1 | UAT-HR-014 passed | 1. Open the payroll journal entry | Debits equal credits. Salary expense is debited; net pay and each deduction liability are credited per §11. | | | | | |
| UAT-HR-016 | Payroll | P1 | A paid run | 1. Attempt to approve, finalize or pay it again | Refused with `INVALID_PAYROLL_STATE`. | | | | | |
| UAT-HR-017 | Payroll | P1 | A period with no active staff | 1. Attempt to generate a run | Refused with `PAYROLL_EMPTY`. | | | | | |
| UAT-HR-018 | Payroll | P2 | A paid run; on `/hr/payroll/{period}` | 1. Open a payslip for one staff member<br>2. Review it | The payslip shows earnings, deductions and net pay, and matches the run. | | | | | |
| UAT-HR-019 | Staff Loans | P1 | HR; on `/hr/staff-loans` | 1. Raise a staff loan: staff member, amount, recovery periods<br>2. Approve it | The loan is recorded. A ledger entry posts **Dr Staff Advance Receivable / Cr Staff Fund** (OSC-6 — confirm the fund is notional). | | | | | |
| UAT-HR-020 | Staff Loans | P1 | An approved staff loan; a payroll run generated afterwards | 1. Open the run and find that staff member | An instalment has been deducted automatically. The amount matches the loan's derived instalment. | | | | | |
| UAT-HR-021 | Staff Loans | P1 | A staff loan part-recovered | 1. Run payroll repeatedly until the loan is fully recovered<br>2. Check the loan balance after each run | The balance decreases each period and stops **exactly at zero**. It never goes negative. The loan closes when it reaches zero. | | | | | |
| UAT-HR-022 | Staff Loans | P1 | A staff loan already in progress | 1. Attempt to raise a second loan for the same staff member | Refused with `STAFF_LOAN_IN_PROGRESS`. | | | | | |
| UAT-HR-023 | Staff Fund | P1 | On `/hr/staff-fund` | 1. Review the fund balance and its movements | Contributions, loans and advances are listed. The balance reconciles with the Staff Fund account in the ledger. | | | | | |
| UAT-HR-024 | Commission | P1 | On `/hr/commission` | 1. Generate commission for a period<br>2. Review the calculation | Commission is calculated from branch profit derived from the ledger. The basis is visible and checkable. | | | | | |
| UAT-HR-025 | Commission | P1 | A branch that made a loss | 1. Generate commission for that branch | Commission is not distributable, or the loss is carried forward per OSC-5. **Confirm the business accepts automatic carry-forward.** | | | | | |
| UAT-HR-026 | Commission | P2 | On `/hr/commission` | 1. Review zone manager commission | Zone commission is calculated and attributed to the correct zone manager. | | | | | |
| UAT-HR-027 | Performance | P2 | On `/hr/performance` | 1. Review staff performance records | Records show metrics, targets, achieved values and the derived achievement rate. | | | | | |
| UAT-HR-028 | HR | P2 | On `/hr/branches` | 1. Review staff by branch | Headcount and payroll cost per branch are shown and reconcile with the staff list. | | | | | |
| UAT-HR-029 | HR | P1 | Logged in as Daniel Kessy (Branch Manager) | 1. Navigate to `/hr/staff` and `/hr/payroll` | Access is refused — a Branch Manager holds no `hr.view`. | | | | | |
| UAT-HR-030 | HR | P1 | Logged in as Khadija Ramadhani (Auditor) | 1. Navigate to `/hr/staff` | Readable — the Auditor holds `hr.view`. No create, edit or approve action is available. | | | | | |

---

## 17. Section 14 — Salary Advance

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-ADV-001 | Salary Advance | P2 | On `/salary-advance/categories` | 1. Review the advance categories<br>2. Create one with its limits<br>3. Save | Created and available when requesting an advance. | | | | | |
| UAT-ADV-002 | Salary Advance | P1 | HR; on `/salary-advance/requests` | 1. Raise an advance request: staff member, category, amount, recovery periods<br>2. Submit | Created in Pending state and listed. | | | | | |
| UAT-ADV-003 | Salary Advance | P1 | A pending advance; logged in as Grace Mbwana (HR) | 1. Approve it | Approved — **HR approves** (§16.7). The approver is recorded. | | | | | |
| UAT-ADV-004 | Salary Advance | P1 | An approved advance; logged in as Grace Mbwana (HR) | 1. Attempt to disburse it | Refused — **Finance disburses** (§16.8). | | | | | |
| UAT-ADV-005 | Salary Advance | P1 | An approved advance; logged in as Catherine Massawe (Finance) | 1. Disburse it | Disbursed. A **balanced ledger entry** is posted. It appears in `/salary-advance/paid`. | | | | | |
| UAT-ADV-006 | Salary Advance | P1 | A pending advance | 1. Reject it with a reason | Rejected. No ledger entry. Reason recorded. | | | | | |
| UAT-ADV-007 | Salary Advance | P1 | A staff member with an advance in progress | 1. Attempt to raise a second advance for them | Refused with `ADVANCE_IN_PROGRESS`. | | | | | |
| UAT-ADV-008 | Salary Advance | P1 | A disbursed advance; run payroll for the next period | 1. Open the run and find that staff member | A recovery instalment has been deducted automatically. | | | | | |
| UAT-ADV-009 | Salary Advance | P1 | An advance being recovered | 1. Run payroll repeatedly until fully recovered<br>2. Check the balance after each run | The balance decreases and stops **exactly at zero**. It never goes negative. The advance closes when it reaches zero. | | | | | |
| UAT-ADV-010 | Salary Advance | P2 | On `/salary-advance/active` and `/salary-advance/repayments` | 1. Review both | Active advances and their repayment history are listed and reconcile with the payroll deductions. | | | | | |
| UAT-ADV-011 | Salary Advance | P1 | A disbursed advance | 1. Attempt to disburse it again | Refused with `INVALID_ADVANCE_STATE`. | | | | | |

---

## 18. Section 15 — Teller

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-TLR-001 | Teller | P1 | Logged in as Joseph Mrema (0754000010, Teller, NEW KALENGE); on `/teller` | 1. Review the customer search screen | Customers the teller may serve are listed with name, customer number, phone, branch and status. Only NEW KALENGE customers appear. | | | | | |
| UAT-TLR-002 | Teller | P2 | On `/teller` | 1. Search by customer name<br>2. Search by customer number<br>3. Search by phone number | Each search returns the correct customer. | | | | | |
| UAT-TLR-003 | Teller | P1 | On `/teller`; a customer with loans | 1. Open that customer's session | The session shows the customer's identity, account status, KYC status, their loans, their outstanding position and their full payment statement. | | | | | |
| UAT-TLR-004 | Teller | P1 | On a teller session | 1. Compare the Outstanding tile against the sum of the loans listed | The figures agree. Collected equals the sum of the payments listed. | | | | | |
| UAT-TLR-005 | Teller | P1 | On a teller session for a customer whose KYC is incomplete or account not approved | 1. Observe the session header | KYC and approval status are visible **before** any figures, so the teller sees a problem before promising anything. | | | | | |
| UAT-TLR-006 | Teller | P1 | On a teller session for a customer with **multiple** loans | 1. Confirm every loan and every payment across all loans is shown | The statement covers all loans, not just one. The total collected is the sum across all of them. | | | | | |
| UAT-TLR-007 | Teller | P2 | On a teller session | 1. Use "Back to customer search" | Returns to `/teller` with the search available. | | | | | |
| UAT-TLR-008 | Teller | P1 | On a teller session for a customer with **no** loans | 1. Observe | A clear empty state states the customer has never borrowed. No error and no misleading zero-balance claim. | | | | | |
| UAT-TLR-009 | Teller | P1 | Logged in as Joseph Mrema (Teller) | 1. Attempt to open `/teller/{id}` for a Kakonko customer by URL | Refused. The teller cannot open a session for a customer outside their branch. | | | | | |

---

## 19. Section 16 — Reports

> The report registry holds **38** reports. Cases UAT-RPT-001 to 004 are the
> generic checks; run them against **every** report in the list at UAT-RPT-020.

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-RPT-001 | Reports | P1 | Logged in with `reports.view`; on `/reports` | 1. Review the report index | All 38 reports are listed and each opens. | | | | | |
| UAT-RPT-002 | Reports | P1 | Any report open | 1. Apply a branch filter<br>2. Apply a period or date-range filter<br>3. Combine both | The report re-runs and the figures change consistently with the filter. Column totals recompute. | | | | | |
| UAT-RPT-003 | Reports | P2 | Any report open | 1. Search within the report<br>2. Sort by each sortable column<br>3. Page forward and back | All behave correctly. The totals reflect the whole filtered set, not just the visible page. | | | | | |
| UAT-RPT-004 | Reports | P1 | Any report open | 1. Export to CSV<br>2. Export to Excel<br>3. Export to PDF where offered<br>4. Open each file | Each file downloads, opens without error, and its contents match what was on screen — same rows, same totals, same filter applied. | | | | | |
| UAT-RPT-005 | Reports | P1 | On the Trial Balance report | 1. Compare against `/ledger` trial balance | The figures are identical. Debits equal credits. | | | | | |
| UAT-RPT-006 | Reports | P1 | On the Financial Statements report | 1. Review the Balance Sheet and P&L<br>2. Confirm with the Finance Manager | Assets = Liabilities + Equity. The P&L reconciles with the income and expense accounts in the ledger. | | | | | |
| UAT-RPT-007 | Reports | P1 | On the Loan Portfolio report | 1. Compare the total outstanding against `/loans/book` | The two figures agree. | | | | | |
| UAT-RPT-008 | Reports | P1 | On the Repayments report | 1. Compare the total collected against the payments list and the ledger | The report reconciles with the ledger to the cent. **Note:** it anchors on posted journal entries, not on payment status `confirmed` — OSC-7. | | | | | |
| UAT-RPT-009 | Reports | P1 | On the Arrears report and Age Analysis | 1. Review the ageing buckets<br>2. Confirm Portfolio at Risk (8+ days) and the PAR ratio | Buckets and PAR match the underlying loan schedules. | | | | | |
| UAT-RPT-010 | Reports | P1 | On the Daily Collection report | 1. Run for today<br>2. Compare with the payments taken today | Figures agree. | | | | | |
| UAT-RPT-011 | Reports | P1 | On the Daily Disbursement report | 1. Run for today<br>2. Compare with the loans disbursed today | Figures agree. | | | | | |
| UAT-RPT-012 | Reports | P1 | On the Branch P&L and Branch Expense reports | 1. Compare Branch Expense against the expense accounts in the ledger | Branch Expense reconciles with the expense-category chart accounts. It is a **subset** of the Branch P&L expense line, not equal to it — confirm the business understands the relationship. | | | | | |
| UAT-RPT-013 | Reports | P1 | On the Payroll and Commission reports | 1. Compare against the payroll runs and commission records | Figures agree with the source records and with the ledger postings. | | | | | |
| UAT-RPT-014 | Reports | P1 | On the Audit Trail report | 1. Locate entries for actions performed earlier in UAT | Every action is present with actor, timestamp and affected record. | | | | | |
| UAT-RPT-015 | Reports | P1 | On the Reversals report | 1. Locate the reversal from UAT-LDG-007 | The reversal is listed with its original entry, the requester and the approver. | | | | | |
| UAT-RPT-016 | Reports | P1 | On the Suspense report | 1. Compare against the suspense queue | Figures agree. | | | | | |
| UAT-RPT-017 | Reports | P1 | On the Executive Summary report | 1. Pick any figure<br>2. Open the report named in its `source` column and find the same figure | The figures are **identical** — the Executive Summary reuses other reports' numbers rather than recomputing them. | | | | | |
| UAT-RPT-018 | Reports | P1 | Logged in as Esther Mollel (Loan Officer, Kakonko) | 1. Open the Loan Portfolio report | Only Kakonko figures are included. §13 applies to reports as it does to lists. | | | | | |
| UAT-RPT-019 | Reports | P1 | Logged in as Joseph Mrema (Teller) | 1. Navigate to `/reports` | Access is refused — a Teller holds no `reports.view`. | | | | | |
| UAT-RPT-020 | Reports | P1 | Logged in with `reports.view` | Open each of the 38 reports in turn and apply UAT-RPT-001 to 004:<br>Loan Portfolio · Repayments · Arrears · Recovery · Cashflow · Branch P&L · Branch Efficiency · HQ Cashflow · Payroll · Commission · Zone Commission · Financial Statements · Audit Trail · Suspense · Reversals · Daily Collection · Daily Disbursement · Branch Ranking · Customer Segmentation · Age Analysis · Repayment Behaviour · Trial Balance · Staff Performance · Staff Payslip · Staff Loan · Staff Advance · Staff Fund Balance · Branch Expense · HQ Expense · HQ Allocation (2%) · Profit Adjustment · Commission Eligibility · Balance Sheet · Cash Position · Daily Position · Growth · Risk · Executive Summary | Every report opens, returns real data (or a clear empty state), filters, sorts, pages and exports correctly. **Record any report that fails individually in Notes.** | | | | | |
| UAT-RPT-021 | Reports | P2 | On the legacy-named report screens: `/reports/daily`, `/reports/branch-wise`, `/reports/cash-transaction`, `/reports/customer-development`, `/reports/customer-statement`, `/reports/default-loan`, `/reports/file`, `/reports/loan-collection`, `/reports/loan-pending`, `/reports/loan-repayment`, `/reports/today-receivable`, `/reports/today-received`, `/reports/write-off` | 1. Open each in turn | Each renders live data. Confirm with the business that each still serves its purpose from the legacy system. | | | | | |

---

## 20. Section 17 — Branch Scope and Separation of Duties

> These are cross-cutting controls. A failure here is **S1 Critical** — stop
> testing and escalate.

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-SEC-001 | Branch Scope | P1 | Logged in as Esther Mollel (Loan Officer, Kakonko) | 1. Note a customer ID belonging to Missenyi (obtain it as Super Admin first)<br>2. Navigate directly to `/customers/{that id}` | Access is refused. The Missenyi customer's data is **not** displayed. | | | | | |
| UAT-SEC-002 | Branch Scope | P1 | As above | 1. Navigate directly to `/loans/{a Missenyi loan id}` | Refused with `BRANCH_SCOPE_VIOLATION`. | | | | | |
| UAT-SEC-003 | Branch Scope | P1 | UAT-SEC-001 and 002 attempted | 1. Log in as Super Admin<br>2. Open `/admin/audit-logs`<br>3. Filter for branch scope violations | Both attempts are logged with the acting user, the target record and the timestamp. | | | | | |
| UAT-SEC-004 | Branch Scope | P1 | Logged in as Hamisi Ally (0754000008, Zone Manager) | 1. Open `/customers` and `/loans` | Records across the branches in the manager's remit are visible — the role holds `branches.view_all`. | | | | | |
| UAT-SEC-005 | Branch Scope | P1 | Logged in as Joseph Mrema (Teller, NEW KALENGE) | 1. Open `/teller` | Only NEW KALENGE customers are listed. | | | | | |
| UAT-SEC-006 | Branch Scope | P1 | Logged in as Frank Urio (Credit Officer, Missenyi) | 1. Attempt to credit-review a Kakonko loan | Refused. §13 makes the Credit Officer strictly branch-scoped, liftable only by the explicit `loans.review_cross_branch` grant. | | | | | |
| UAT-SEC-007 | Separation of Duties | P1 | A loan raised by Esther Mollel | 1. Confirm Esther cannot approve it<br>2. Confirm the approving Branch Manager cannot then disburse it<br>3. Confirm Finance disburses | Three different people are required to move one loan from application to disbursement. | | | | | |
| UAT-SEC-008 | Separation of Duties | P1 | A payroll run | 1. Confirm HR generates and approves<br>2. Confirm HR cannot pay<br>3. Confirm Finance finalizes and pays | §16.7 and §16.8 are enforced. | | | | | |
| UAT-SEC-009 | Separation of Duties | P1 | An expense, a bank transaction, an HQ transaction and a float transfer | 1. For each, confirm the requester cannot approve their own request | All four enforce the rule. | | | | | |
| UAT-SEC-010 | Separation of Duties | P1 | A ledger reversal | 1. Confirm the requester cannot approve their own reversal | Enforced. | | | | | |
| UAT-SEC-011 | Access Control | P1 | For each of the 11 roles in turn | 1. Log in<br>2. Walk the visible navigation<br>3. Attempt one action the role should **not** have | Each role sees only its own functions. Every out-of-role action is refused. Record any role that sees more than it should. | | | | | |
| UAT-SEC-012 | Data Protection | P1 | Logged in as any user | 1. Open browser developer tools<br>2. Inspect network responses and page source for an API bearer token | **No API token is present in the browser.** The session is a sealed server-side cookie; API calls are made server-side only. | | | | | |

---

## 21. Section 18 — Non-functional

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-NFR-001 | Performance | P2 | Logged in as Super Admin | 1. Open `/dashboard` and time it<br>2. Open `/loans/book` and time it<br>3. Open `/customers` and time it | Each page renders within an acceptable time agreed with the business (suggested: under 5 seconds on the UAT network). Record actual timings. | | | | | |
| UAT-NFR-002 | Performance | P1 | On `/loans/book` with the full loan book | 1. Open the page<br>2. Confirm the Outstanding tile is populated | The balance appears without a long delay. It is now carried on the loan list itself rather than fetched per loan. | | | | | |
| UAT-NFR-003 | Performance | P2 | Logged in as any user | 1. Navigate rapidly between 10 different pages in under a minute | Pages continue to load. If a rate limit is reached, a clear message appears rather than a blank error. Record if this occurs in normal use. | | | | | |
| UAT-NFR-004 | Usability | P2 | Any list screen with no matching records | 1. Apply a filter that matches nothing | A clear empty state explains the list is empty and what would populate it. No raw placeholder text, no blank white area. | | | | | |
| UAT-NFR-005 | Usability | P1 | Every screen visited during UAT | 1. Watch for placeholder or developer text such as `EMPTY_`, `TODO`, `Lorem ipsum`, `undefined`, `null`, `NaN` | None appears anywhere. Record the screen and the text if any is seen. | | | | | |
| UAT-NFR-006 | Reliability | P2 | Any form | 1. Submit a form<br>2. Immediately press Back<br>3. Re-submit | No duplicate record is created. | | | | | |
| UAT-NFR-007 | Reliability | P2 | API deliberately stopped by IT | 1. Open any data page | A clear error is shown stating the system is unavailable. **The page does not show stale or fabricated data.** | | | | | |
| UAT-NFR-008 | Usability | P2 | Any screen | 1. Check all monetary values | Amounts are formatted consistently with thousands separators and 2 decimal places. No floating-point artefacts such as `1000.0000001`. | | | | | |
| UAT-NFR-009 | Usability | P2 | Any screen showing dates | 1. Check all dates | Dates are formatted consistently and are correct for Africa/Dar_es_Salaam. No timezone drift by a day. | | | | | |
| UAT-NFR-010 | Usability | P3 | Any screen | 1. View at 1366×768<br>2. View at 1920×1080 | Layout is usable at both. Wide tables scroll horizontally within their own container rather than breaking the page. | | | | | |
| UAT-NFR-011 | Reliability | P1 | End of the UAT cycle | 1. Re-run the trial balance | **Debits equal credits.** Every transaction created across the whole UAT has kept the books in balance. | | | | | |

---

## 22. Section 19 — Excluded Modules and Known Gaps

> These cases exist so the sign-off record shows each item was **considered and
> excluded**, not overlooked. Expected behaviour is "does not work" — mark Pass
> when the system behaves as documented below.

| ID | Module | Priority | Preconditions | Test Steps | Expected Result | Actual Result | Pass/Fail | Tester | Date | Notes |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| UAT-EXC-001 | Agent | P1 | Logged in as Super Admin | 1. Open `/agent/deposits`, `/agent/payment-modes`, `/agent/transactions` | The screens render but show **reference data only**. No backend module exists. Confirm with the business that Agent is out of scope for this release. | | | | | |
| UAT-EXC-002 | Insurance | P1 | Super Admin | 1. Open `/insurance/balance`, `/insurance/movements`, `/insurance/today`, `/insurance/today-withdrawals` | As above. Confirm Insurance is out of scope. | | | | | |
| UAT-EXC-003 | VISA | P1 | Super Admin | 1. Open `/visa` | As above. Confirm VISA is out of scope. | | | | | |
| UAT-GAP-001 | Repayments | P1 | A cash payment taken during UAT | 1. Look for a bank reconciliation function<br>2. Check whether any payment reaches status Confirmed | **No reconciliation function exists.** No payment reaches Confirmed. Collections reports anchor on the ledger instead. Confirm the business accepts this for go-live, or defer go-live until it is built (OSC-7). | | | | | |
| UAT-GAP-002 | Ledger | P1 | Finance | 1. Look for a month-end close function | **Not built.** The Profit Account is never posted. Commission is derived from the ledger directly, so payroll is unaffected. Confirm acceptance. | | | | | |
| UAT-GAP-003 | Loans | P1 | Finance | 1. Look for a write-off function on a defaulted loan | **Not built.** The Recovery report lists loan states rather than ledger balances. Confirm acceptance. | | | | | |
| UAT-GAP-004 | Capital | P2 | Finance | 1. Look for a dividend declaration function | **Not built.** Capital can be received; nothing can be distributed. Confirm acceptance. | | | | | |
| UAT-GAP-005 | Notifications | P1 | Super Admin | 1. Perform an action that should trigger an SMS (e.g. approve a loan)<br>2. Check whether any message was sent<br>3. Open the notification bell in the header | **No message is sent** — templates exist but nothing dispatches them. The bell shows placeholder content because no notification endpoint exists. Confirm acceptance or defer go-live. | | | | | |
| UAT-GAP-006 | Integrations | P1 | Any registration | 1. Confirm the NIDA lookup is simulated (OTP `123456`)<br>2. Confirm the e-mandate OTP is simulated (`654321`)<br>3. Confirm Vodacom KYC result is supplied, not fetched<br>4. Confirm no outbound disbursement call is made to the provider | All four are simulated. **NIDA in particular blocks real customer onboarding** — §9 forbids hand-typed identity data. Confirm the integration timeline before go-live. | | | | | |

---

## 23. Defect log

| # | Test Case ID | Date | Raised by | Description | Severity | Status | Resolved | Re-tested by | Re-test date |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 1 | | | | | | | | | |
| 2 | | | | | | | | | |
| 3 | | | | | | | | | |
| 4 | | | | | | | | | |
| 5 | | | | | | | | | |
| 6 | | | | | | | | | |
| 7 | | | | | | | | | |
| 8 | | | | | | | | | |
| 9 | | | | | | | | | |
| 10 | | | | | | | | | |

---

## 24. Execution summary

Complete at the end of the cycle.

| Section | Cases | Passed | Failed | Blocked | Not run |
| --- | --- | --- | --- | --- | --- |
| 1 · Authentication and Access Control | 18 | | | | |
| 2 · Administration and Organization | 32 | | | | |
| 3 · Customer Management | 37 | | | | |
| 4 · Groups | 8 | | | | |
| 5 · Loan Origination | 40 | | | | |
| 6 · Repayments and Collections | 20 | | | | |
| 7 · Penalties and Fees | 10 | | | | |
| 8 · Ledger and Journal | 12 | | | | |
| 9 · Treasury and Bank | 15 | | | | |
| 10 · Capital and Float | 13 | | | | |
| 11 · Headquarters | 8 | | | | |
| 12 · Expenses | 11 | | | | |
| 13 · HR and Payroll | 30 | | | | |
| 14 · Salary Advance | 11 | | | | |
| 15 · Teller | 9 | | | | |
| 16 · Reports | 21 | | | | |
| 17 · Branch Scope and Separation of Duties | 12 | | | | |
| 18 · Non-functional | 11 | | | | |
| 19 · Excluded Modules and Known Gaps | 9 | | | | |
| **Total** | **327** | | | | |

### Business decisions to confirm during UAT

These are open specification conflicts. Each has an implemented reading that
works; the business must confirm the reading is the intended one. They are
raised at the test cases noted.

| Ref | Decision | Raised at |
| --- | --- | --- |
| OSC-1 | Penalty recognised on collection (current) or on accrual | UAT-PEN-002 |
| OSC-2 | Ratify the widened penalty rate column | UAT-ADM-026 |
| OSC-3 | Interest rate is per-installment (current) or per-annum — **this changes every price** | UAT-ADM-022 |
| OSC-4 | Penalty base includes accrued penalty (current) or excludes it | UAT-ADM-026, UAT-PEN-003 |
| OSC-5 | Loss carry-forward is automatic (current) or set by Finance | UAT-HR-025 |
| OSC-6 | Staff Fund is a notional liability (current) or is banked | UAT-HR-019 |
| OSC-7 | "Collected" means ledger-posted (current) or bank-reconciled | UAT-RPT-008, UAT-GAP-001 |
| OSC-8 (candidate) | Head office is the branch on the company profile (current) or the flagged branch | UAT-ADM-004, UAT-CAP-004 |

### Acceptance statement

> We confirm that User Acceptance Testing of MikopoFasta ERP has been completed
> as recorded in this document. All Priority 1 test cases have passed, or have
> failed and been accepted in writing as known issues listed in the defect log.
> The business decisions above have been confirmed. We accept the system for
> production deployment subject to the outstanding items recorded here.

| Role | Name | Signature | Date |
| --- | --- | --- | --- |
| UAT Lead | | | |
| Operations Manager | | | |
| Finance Manager | | | |
| Head of Credit | | | |
| HR Manager | | | |
| IT / Systems | | | |
| Managing Director | | | |
