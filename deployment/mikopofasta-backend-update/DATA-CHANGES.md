# Data changes

Every development data/configuration change, and what production should do
about each one.

**The headline: this package seeds nothing.** Not one row of business data is
inserted by any migration or script here. That is a deliberate decision made at
commit `d531271` — which customer types an institution serves, which documents
it accepts, which sectors and employers it lends against are the institution's
decisions, and this application stopped shipping them.

The consequence you need to plan for: **several new features do nothing until an
administrator configures them.** Nothing breaks, nothing regresses, and the
system behaves exactly as it does today — but the new capability is dormant.
The table below says which.

---

## A. Schema changes — applied by `php artisan migrate --force`

| Change | Development change | Production action |
|---|---|---|
| `customer_categories.is_active`, `.sort_order`, `.description` | Added, so a customer type can be retired without deleting it | Automatic. `is_active` defaults **true**, so every existing type stays offered |
| `customer_categories.form_title` | Added — the heading the registration form shows over a type's own questions | Automatic. Null means "use the name" |
| `customer_categories.optional_documents` | Added — documents offered but not required | Automatic. Null behaves as empty |
| `loan_products.min_repayments`, `.max_repayments`, `.allows_deduction`, `.approval_stage_id`, `.topup_percent`, `.take_home_percent` | Added for the Loan Category screen | Automatic. All nullable or defaulted |
| `loan_product_branches` | New pivot table | Automatic. **Empty means every branch** — existing products stay available everywhere |
| `id_types.document_type_id` | Added — which document evidences an identity type | Automatic, null. See section B.2 |

No column is dropped. No table is emptied. No existing row is rewritten.

---

## B. Reference data — **manual, and required for the new features to work**

### B.1 Customer Type registration forms — *required if you want dynamic registration*

**Development:** the two customer types were given a `form_title`, a field list
(`dynamic_form_schema`) and a document list.

**Production:** configure each customer type at
**Administration → Customer Types → (row) → Registration form**.

Until you do, a customer type asks nothing beyond the basic information and
requires no documents — which is exactly what it does today. Nothing breaks.

For each type you configure:
- **Section title** — e.g. `PUBLIC SERVANT DETAILS`
- **Fields** — label, type, whether required, which admin-managed list a select
  draws on, which field it follows, and where the answer is kept
- **Required / optional documents** — chosen from Document Types

### B.2 ID Type → Document Type links — *required for the identity document step*

**Development:** each ID type was linked to the document that evidences it.

**Production:** **Administration → Master Data → ID Types → (edit) → "Document
that proves it"**.

> **This one has a visible consequence.** Until an ID type is linked, step 3 of
> registration shows **no identity-document upload slot** for it, and the
> customer's KYC does not require one. That is the same behaviour as today. Once
> linked, the slot appears, it is required, and registration will not save
> without it.
>
> Link them deliberately, one at a time, and check the registration screen after
> the first one.

Leave an ID type unlinked where the institution accepts it without taking a
copy. Null is a valid, meaningful configuration.

### B.3 Sectors, Sector Categories, Employers, Contract Types, Document Types, Marital Statuses

**Development:** demonstration rows exist.

**Production:** whatever the institution already has is kept and is correct.
Add more at **Administration → Master Data → …** (sector categories are managed
*inside* a sector). No action required unless a configured registration field
points at a list that is empty — the field then shows an empty dropdown naming
the screen to fill it on.

> One rule to preserve: a contract type whose **code** is `TEMPORARY` is what
> makes a contract-expiry field conditionally required. The rule keys on the
> code, never the name, so the label may be renamed or translated freely.

### B.4 Loan product → branch assignments

**Development:** none created.

**Production:** none needed. **An empty assignment list means the product is
available at every branch**, so existing products are unaffected. Assign
branches only where you want to restrict a product:
**Administration → Loan Category → (row) → Assign Branch**.

---

## C. Configuration

| Change | Development change | Production action |
|---|---|---|
| API rate limits | Authenticated reads raised to 600/min; writes stay 120/min | Automatic, in `AppServiceProvider`. Login, password-reset and webhook limits are **unchanged** |
| `/api/v1/master-data` | New batch endpoint returning every list in one response | Automatic. The single-list routes are unchanged and still work |

No `.env` change. No new config file. No new dependency.

---

## D. Test data — **must never reach production**

None of the following is in this package, and none should be created on the
production server:

- Test customers (including any named "Conrad")
- Test branches, test users, test loans
- Fake customer documents or face scans
- Demonstration customer types, sectors or employers from the development database
- `CustomerCategorySeeder`, `CustomerSeeder`, `LoanSeeder`, `ReferenceDataSeeder`
  and the other demonstration seeders — **do not run any of them.** Production
  runs `ProductionSeeder` only, and it creates no business data.

If you need to confirm this package is clean:

```bash
grep -rniE "insert|updateOrCreate|::create\(" mikopofasta-backend-update/database/migrations/
```

That returns nothing. The migrations add structure and no rows.

---

## E. Data that could be overwritten — none

No step in this package updates an existing row. The one write path that
changes behaviour for existing records is worth naming so it is not a surprise:

**`customers.marital_status` is now derived from `customers.marital_status_id`
on the next save of a customer.** It is written only when a registration or an
update supplies a marital status, matched on the list entry's **code**. It never
clears an existing value, and an update that does not mention marital status
leaves both columns alone. Existing customers are untouched until somebody edits
them.
