# Fresh installation runbook

How to bring up a new MikopoFasta installation, in order.

**This application ships no institutional data.** Structure, API, admin screens and
empty states are in the box; which banks you work with, where you lend, what you lend
and who approves it are yours to configure. A freshly migrated database has **empty**
business tables, and registration will refuse to complete until the mandatory sections
below are done.

Steps marked **MANDATORY** must be finished before a Loan Officer can register a single
customer. Steps marked *Optional* can wait, but the feature they govern stays
unavailable until they are done.

---

## A. Deploy the application

Backend and frontend, `.env` configured, storage writable.

Confirm before continuing:

- `APP_KEY` is set — signed KYC document URLs depend on it.
- The `kyc` disk path exists and is writable, and is **not** inside `public/`.
- Database credentials are correct and the database is empty.

## B. Run migrations — **MANDATORY**

```bash
php artisan migrate --force
```

Creates every table. **Creates no business data.** Verified by
`tests/Feature/Platform/FreshInstallTest.php`, which fails if any migration starts
shipping reference rows again.

## C. Run ProductionSeeder — and only this seeder — **MANDATORY**

```bash
php artisan db:seed --class=ProductionSeeder --force
```

It creates only what the application cannot start without:

| | Why it is not business data |
|---|---|
| Permissions and roles | The application's own vocabulary. Every policy and route names them. |
| The System account | A platform rule, not a user. The ledger attributes automated postings to it. |
| Chart of accounts | Double-entry structure. Nothing can post without it. |
| Default account-type requirement row | The fallback profile. Registration returns **503** without it. |

**Never run `php artisan db:seed` without `--class`.** The bare command runs
`DatabaseSeeder`, which is the development and demonstration seeder: it creates a whole
fictional institution — branches, staff, customers, loans, a ledger with activity. That
is correct for a developer and wrong for you.

## D. Administrator account and permissions — **MANDATORY**

Create the first Super Admin. Everything below needs `admin.org_settings`.

Then confirm the roles your institution actually uses are enabled, and that the people
who will do the configuration hold them.

## E. Master Data — **MANDATORY (most of it)**

**Administration → Master Data.** One screen, fourteen lists. Each shows a count beside
its name; a zero is shown in amber because a zero is the thing you need to see.

| List | Needed before registration? | What depends on it |
|---|---|---|
| **Account Types** | **MANDATORY** | Decides which registration steps are required at all. |
| **ID Types** | **MANDATORY** | No identity document can be recorded without one. |
| **Customer Types** | **MANDATORY** | Registration asks every customer for their legal form. |
| **Banks** | **MANDATORY** if any account type requires bank details | The bank step cannot be completed. |
| **Document Types** | **MANDATORY** | A category's required documents point at these codes. |
| Loan Types | Recommended | The lending programme a customer is registered under. |
| Marital Statuses | Recommended | Asked wherever the account type requires it. |
| Occupations · Work Types · Employment Types | *Optional* | Occupation is also accepted as free text. |
| Mobile Money Providers | *Optional* until disbursement | Needed before mobile disbursement. |
| Contract Types | *Optional* | Needed only for categories requiring a contract. See the note below. |
| Sectors · Employers | *Optional* | See F and G. |

### The one code that carries a rule

A contract type whose **code** is `TEMPORARY` requires a contract expiry date; anything
else refuses one. The *name* is yours — rename or translate it freely. If you want a
fixed-term contract type that demands an expiry, give it that code.

## F. Sectors and cadres — *Optional, unless a category requires a sector*

**Administration → Master Data → Sectors.** Create the employing body, then its
categories underneath it in the same screen.

A sector with no cadres is unusable: registration asks for both levels and refuses a
cadre belonging to another sector, so a sector on its own produces a dropdown that
dead-ends. The screen says so when a sector has none.

A cadre code is unique **within** its sector — two bodies may each have an
"Administration" cadre and they are not the same job. A cadre cannot be moved between
sectors afterwards.

## G. Employers — *Optional, unless a category requires an employer*

**Administration → Master Data → Employers.**

Deliberately a **separate list from Sectors**: a public servant serves a body that has
cadres inside it, a private employee works for a company that does not. A category asks
for one or the other, never both.

## H. Geography — **MANDATORY**

**Administration → Geography.** Region → District → Ward → Street, imported from a CSV.

```csv
region,district,ward,street
Region A,District A,Ward A,Street A
Region A,District A,Ward A,Second Street
Region A,District B,,          ← stops at district: valid
Region B,,,                    ← region only: valid
```

- A ward needs its district on the same row; a street needs its ward. Rows breaking that
  are **rejected by line number and reason**, and the rest of the file still loads.
- Matching is case-insensitive and trimmed, so one place stays one row.
- **Safe to run twice.** Re-importing creates nothing. Fix the rejected rows and
  re-import the whole file.
- Limits: 20 MB, 200,000 rows. Split a larger register and import each part.

Source the register from the National Bureau of Statistics or the TAMISEMI
administrative list and rename its columns to match. **This application contains no
geographical data of its own, and none is invented on your behalf.**

Registration requires all four levels to be chosen. Until this is imported, an officer
cannot record an address — the control says so and names this screen rather than
offering a free-text box.

## I. Customer Categories — **MANDATORY**

**Administration → Customer Categories.** The category is the rule engine: it decides
which questions a customer is asked, which documents they must produce, which loan
products they may take, and their risk tier.

Each category declares:

- **Dynamic form fields** — the questions peculiar to this kind of customer.
- **Required documents** — codes from the Document Types list in E.
- **Requires sector / employer / contract / salary** — which first-class employment
  blocks registration should ask for. A public servant needs a sector and cadre; a
  private employee needs an employer; a boda rider needs none of them.
- **Risk tier** and whether it **requires extra approval**.

## J. Category → Product eligibility — **MANDATORY before lending**

**Administration → Registration & Eligibility → Category eligibility.**

Tick the products each category may borrow and set an optional per-category ceiling.
Leaving a cap empty uses the product's own maximum. **A product not ticked is refused by
the loan gate** with `CATEGORY_NOT_ELIGIBLE_FOR_PRODUCT`.

Do this after N (Loan Products), since it references them.

## K. Account Type Requirements — **MANDATORY**

**Administration → Registration & Eligibility → Registration requirements.**

Per account type: which blocks registration demands (employment, business, bank, card,
category, marital status, address, identity document, face verification), and the
guidance line an officer sees.

Two integrations warrant care: **NIDA verification** and **SMS OTP**. Turning either on
before its integration exists stalls every customer visibly at KYC — the requirement is
reported as *blocked*, not silently enforced. Leave them off until the integration is
real.

## L. Minimum guarantors — **MANDATORY**

Set on the same screen, per account type.

This is now the **only** source of truth. Registration enforces it when the customer is
created, and the loan eligibility engine reads the same number — they cannot disagree.
Setting it to `0` is meaningful and supported: a savings account type may legitimately
require none.

## M. Required documents — **MANDATORY (the list), optional (the enforcement)**

The list itself is part of each category (step I). Whether a missing document **blocks**
is a separate switch, and it ships **off**.

## N. Document-enforcement cutoff — *Optional, and deliberately last*

**Administration → Registration & Eligibility → Category documents.**

Two settings:

| Setting | Effect |
|---|---|
| Off (default) | Missing documents are a checklist. Nothing is blocked. |
| On, **no date** | Blocks **every** customer, including everyone already registered. |
| On, **with a date** | Blocks only customers registered on or after that date. |

> **On a fresh installation, turning this on with no date is harmless — there are no
> customers yet.** On an installation with an existing book it is not: every customer
> missing a document their category requires stops being loan-eligible the next time
> their KYC is recomputed. The screen warns before you save it.

Enforcement is also **lazy**: an existing customer's stored KYC status does not change
until it is next recomputed. That is a second safety layer, not a reason to skip the
date.

## O. Loan Products — **MANDATORY before lending**

**Administration → Loan Products.** Interest formula and rate, minimum and maximum
amount, tenure range, grace period, processing and insurance fees, commission rates,
penalty configuration, and whether the product requires an e-mandate.

**Interest formulas are structural, not configuration.** Each of the four maps to an
amortisation algorithm implemented in code — Simple, Flat, Reducing (equal principal),
and Reducing (equal instalment / EMI). You can edit their descriptions; you cannot add a
fifth, because a fifth would be a formula with nothing behind it.

## P. Fees and penalties — **MANDATORY before lending**

**Administration → Loan Fees** and **Administration → Penalty**.

Penalty *types* — percentage of overdue, flat fee, percentage per day — are structural
in the same sense: each is a calculation branch in code. The rates, caps and grace days
are yours.

Also set **Administration → Reserve Setting**, which decides the share of interest held
back as reserve.

## Q. Repayment Schedules — **MANDATORY before lending**

**Administration → Repayment Schedules.** Frequency drives every instalment date, so a
schedule with loans on it cannot have its frequency changed afterwards.

Attach the permitted schedules to each product in O.

## R. Loan Approval Chain — **MANDATORY before lending**

**Administration → Loan Approval Chain.**

Configure the tiers a submitted loan walks, in order. Branch Manager → Zone → Head
Office Credit is one institution's arrangement, not the application's; two tiers or five
is equally valid.

Per stage: order, name, the status a loan waits at, the permission that may decide it,
and three flags — *only for branches in a zone*, *e-mandate must be live first*, and
*issues the customer payment reference*.

- **Loans already in flight are unaffected.** Each carries the chain it was raised
  under, so edits apply to the next application. This is safe to change during business
  hours.
- **With no active stage, a submitted loan goes straight to Finance.** The screen says so
  when every stage is off.
- A stage cannot be deleted once loans have been decided at it — deactivate it instead,
  which keeps the decision history readable.

## S. Verification

Before opening the system, confirm end to end:

1. **Administration → Master Data** — no list that step E marked mandatory shows a zero.
2. **Administration → Geography** — the four counts match the register you imported.
3. Register one test customer through the whole wizard. Every dropdown offers real
   options; none dead-ends.
4. Complete their KYC and face verification.
5. Approve the registration as a Branch Manager.
6. Confirm they appear in the loan applicant search — this proves the whole chain:
   registered → KYC → face → approved → active.
7. Raise a loan and walk it through every configured approval stage.
8. Confirm the payment reference is issued at the stage you flagged for it.
9. **Reverse the test data** or leave it clearly marked. It is real data in a real
   database.

## T. Open the system

Only now grant Loan Officer, Teller and Branch Manager access.

---

## Optional: existing installations

An installation migrated from an earlier version may already hold reference rows that
earlier versions shipped — document types, interest formulas, penalty types, and
whatever `DatabaseSeeder` created if it was ever run.

**Nothing deletes them, and no migration will.** They are ordinary rows: rename them,
deactivate them, or delete the unreferenced ones from Administration → Master Data.

If you want a clean slate, do it as a deliberate manual operation, in this order, and
only on a database you have backed up:

1. Deactivate rather than delete anything a customer or loan already references — the
   foreign keys refuse the delete, and a record whose loan type vanished cannot be read.
2. Delete only unreferenced entries.
3. Re-import geography if the register was wrong.

There is no automated cleanup and there deliberately will not be one: destroying
reference data a live book points at is not something a script should decide.

---

## Quick reference — the mandatory set

Registration **cannot complete** until all of these exist:

```
Account Types · ID Types · Customer Types · Document Types
Banks              (if the account type requires bank details)
Geography          Region → District → Ward → Street
Customer Categories
Account Type Requirements, including minimum guarantors
```

Lending **cannot complete** until additionally:

```
Loan Products · Fees · Penalties · Repayment Schedules
Category → Product eligibility
Loan Approval Chain (at least one active stage)
```
