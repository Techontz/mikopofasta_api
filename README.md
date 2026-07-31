# Mikopofasta API

Backend for the **Mikopofasta Enterprise Microfinance Operating System** — a
Laravel 12, API-only service consumed by the Next.js frontend in
`../mikopofasta_web`.

The frontend is complete and is the API contract. Every endpoint, permission
string, status enum and ledger posting implemented here must match the two
approved specifications:

- `../mikopofasta_web/docs/backend-architecture-specification.md`
- `../mikopofasta_web/docs/frontend-technical-specification.md`

> **Status: Phase 5 complete — Loan Origination.**
> Identity/RBAC (2), organization (3), customers/KYC (4) and loan origination
> through disbursement *preparation* (5) are implemented. Repayments, Ledger,
> Treasury, HR and Reports have not been started. See [Roadmap](#roadmap).

---

## Requirements

| Component | Minimum | Verified on |
|---|---|---|
| PHP | 8.3 | 8.3.24 |
| Composer | 2.x | 2.8.9 |
| MySQL | 8.0 | 9.3.0 |
| Redis | 6.x | 8.8.1 |

Required PHP extensions: `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`,
`xml`, `ctype`, `json`, `bcmath`, `curl`, `fileinfo`, `zip`, `intl`.

`predis/predis` is used as the Redis client so the `phpredis` C extension is
not a hard requirement. If `phpredis` is available in production, set
`REDIS_CLIENT=phpredis` for materially better throughput.

---

## Setup

```bash
cd mikopofasta_api

composer install
cp .env.example .env
php artisan key:generate

# Create both schemas — the test suite runs against real MySQL, not SQLite.
mysql -u root -e "CREATE DATABASE mikopofasta      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE mikopofasta_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

php artisan migrate
php artisan db:seed        # roles, permissions, and the 11 demo accounts
```

Then edit `.env` — at minimum `DB_USERNAME`, `DB_PASSWORD`, and
`CORS_ALLOWED_ORIGINS` if the frontend is not on `http://localhost:3000`.

### Demo accounts

One per role, mirroring the frontend's `lib/mock-data/users.ts`. Every account
uses the password `password`, and sign-in is by **phone**, not email.

| Phone | Name | Role |
|---|---|---|
| 0754000001 | Amina Juma | `super_admin` |
| 0754000002 | Baraka Mushi | `admin` |
| 0754000003 | Catherine Massawe | `finance` |
| 0754000004 | Daniel Kessy | `branch_manager` |
| 0754000005 | Esther Mollel | `loan_officer` |
| 0754000006 | Frank Urio | `credit_officer` |
| 0754000007 | Grace Mbwana | `hr` |
| 0754000008 | Hamisi Ally | `zone_manager` (+ `loans.review_cross_branch`) |
| 0754000009 | Irene Komba | `regional_manager` |
| 0754000010 | Joseph Mrema | `teller` |
| 0754000011 | Khadija Ramadhani | `auditor` |

```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"phone":"0754000001","password":"password"}'
```

### Running

```bash
php artisan serve                 # http://localhost:8000
php artisan queue:work redis --queue=ledger,notifications,reports,default
```

Or all processes at once (server + queue worker + log tail):

```bash
composer dev
```

Verify it is up:

```bash
curl http://localhost:8000/api/v1/health
# {"data":{"service":"Mikopofasta","api_version":"v1","environment":"local","status":"ok"}}
```

---

## Commands

| Command | What it does |
|---|---|
| `composer test` | Clears config cache, runs the Pest suite |
| `composer lint` | Pint in `--test` mode (fails on unformatted code) |
| `composer format` | Pint, applying fixes |
| `composer analyse` | PHPStan / Larastan at level 6 |
| `composer check` | lint → analyse → test (run before every commit) |

---

## Architecture

The application is organised Domain-Driven. **`app/Domain/*` holds behaviour;
`app/Models` holds persistence.** The full layout, the dependency rules and
where each kind of class belongs are documented in
[`app/Domain/README.md`](app/Domain/README.md) — read it before adding code.

Domains: `Auth`, `Organization`, `Customers`, `Loans`, `Repayments`, `Ledger`,
`Treasury`, `HR`, `Reports`.

### Routing

| Mount | File | Auth |
|---|---|---|
| `/api/v1/*` | `routes/api.php` | Sanctum bearer token |
| `/webhooks/*` | `routes/webhooks.php` | Per-provider HMAC signature |
| `/up` | framework health probe | none |
| `/` | `routes/web.php` | none — JSON service identity |

The `/api/v1` prefix is applied in `bootstrap/app.php` rather than by the
framework's default `api:` routing, so a future `/api/v2` is a one-line
addition.

Webhooks are deliberately **unversioned and outside** the Sanctum group:
providers authenticate with a signature header, not a bearer token, and their
callback URLs must stay stable across API versions (spec §1).

### Authentication & RBAC (Phase 2)

**Sign-in is by phone.** The frontend's login form posts phone + password and
`users.email` is nullable (spec §2.1), so phone is the identifier throughout.

**One role per user.** `users.role_id` is the authoritative column, exactly as
spec §2.1 defines it. Spatie's `model_has_roles` pivot is *derived* state, kept
in sync by `User::booted()` so that Spatie's permission resolution, middleware
and Gate integration keep working. Nothing writes to that pivot directly, and
a test asserts the two never drift.

**`extraPermissions` are per-user grants.** Spatie's `model_has_permissions`
carries them. This is how spec §13/§14 Decision 1 is represented: cross-branch
loan review (`loans.review_cross_branch`) is granted to no role by default and
is only ever attached to an individual.

**Token abilities mirror permissions.** Per spec §1, an issued Sanctum token
carries the user's effective permission list as its abilities, never `*`, so a
stolen token cannot exceed the issuing user's authority. Consequently tokens
are revoked whenever the underlying authority changes:

| Event | Tokens revoked |
|---|---|
| Logout | current only |
| Password change / reset | all (the old credential is no longer trusted) |
| Role changed | all (old abilities would otherwise persist) |
| Account suspended | all (otherwise suspension only bites at next login) |
| Account deleted | all |

**The permission matrix is live.** Authorization always reads
`role_has_permissions`, never the seed defaults in `RolePermissionMatrix` —
otherwise the matrix screen would be cosmetic. `RolePermissionMatrix` exists to
seed the §14 baseline and to power the drift test that proves the seed matches
the frontend.

> ⚠️ **Authorize through policies, never through token abilities.**
> Editing the permission matrix does **not** revoke the tokens of users holding
> that role — unlike changing an individual's role, which does. Those tokens
> keep the abilities they were minted with, so their ability list can be stale.
>
> This is currently harmless because nothing authorizes on abilities: every
> check goes through a Policy, which reads the database and is therefore always
> current. Abilities exist only as the defence-in-depth layer spec §1 asks for.
>
> Adding `->middleware('abilities:…')` or calling `tokenCan()` to gate an
> endpoint would turn that staleness into a real privilege-persistence hole.
> If ability-based gating is ever wanted, revoke the affected users' tokens in
> `UpdateRolePermissionsAction` at the same time. Mass logout was not
> implemented now because it is a user-visible behaviour the specification does
> not call for — frontend spec §2 step 6 instead bounds this drift with a short
> session TTL plus a `refresh()` after any matrix change.

**Super Admin is granted via `Gate::before`.** It cannot be revoked through the
permission matrix, and it covers future modules whose policies do not exist
yet. `super_admin`'s grants are also rejected for editing (409
`ROLE_NOT_EDITABLE`).

### Organization structure (Phase 3)

Two independent oversight groupings hang off the same branch set (§12):
**zones** are a commission/oversight grouping, **regions** are geographic. HQ
is a branch row flagged `is_head_office`, not a separate table
(§12 Decision 2) — so every branch-scoped report runs against it unchanged.

`SetHeadOfficeAction` is the only path that moves the flag. It demotes the
incumbent and promotes the target in one transaction, and moves
`company_profiles.headquarters_branch_id` with it, so the system never has two
head offices, none, or a Company Profile screen that disagrees with the
branches table.

**Branch scoping (§13)** is resolved by `BranchScope`, which reconciles the two
documents. §13 resolves scope by role (zone manager → their zone, regional
manager → their region, HQ → everything); the frontend gates it on a single
`branches.view_all` permission. Taken alone the permission would show a Zone
Manager every branch in the country, which §13 explicitly does not allow; taken
alone §13 has no gate. So:

| User | Sees |
|---|---|
| No `branches.view_all` | Own branch **plus its sub-branches** |
| `branches.view_all` + `zone_id` | Every branch in that zone |
| `branches.view_all` + `region_id` | Every branch in that region |
| `branches.view_all`, neither set | Every branch (HQ roles) |

Sub-branches travel with their parent because a sub-branch rolls up into it for
reporting (§12) — seeing the parent but not its children would mean reading
incomplete totals.

Scope is **visibility only**, never authority to act: §13 is emphatic that
cross-branch *loan review* needs the separate, explicit
`loans.review_cross_branch` grant. Reaching a branch outside scope returns
`BRANCH_SCOPE_VIOLATION` **and writes an `audit_logs` row** — §13 treats
cross-branch snooping as itself auditable. That is why the scope check lives in
`BranchScopeGuard` and not in `BranchPolicy`: a policy can only answer yes or
no, so refusing there would produce a generic `FORBIDDEN` with no audit row.

**Read vs write.** Branch, zone, region and address reads are open to any
authenticated user — they are reference data the branch switcher, the branch
form and the customer registration wizard all need, and a Loan Officer holds no
admin permission. Writes require `admin.org_settings`. `GET /branches` is
unpaginated by default (pass `?paginate=1`) because those callers load it whole.

### Customers & KYC (Phase 4)

**NIDA is the source of truth.** Spec §9 forbids hand-typing identity: the
wizard fills name, date of birth and gender from the lookup. `NidaRegistry`
stands in for the real integration and reproduces the frontend's simulator
exactly — including its 32-bit `hash * 31 + char` — so a given NIDA number
resolves to the same person on both sides. It is the seam: replacing it with an
HTTP client against the real registry is the only change needed.

**KYC status is derived, never asserted.** `KycEvaluator` owns the five-item
checklist from §9 (NIDA, OTP, face, additional data, category) and every write
path calls `refresh()` rather than setting the column. It is two-way on
purpose: removing a customer's bank details genuinely makes them incomplete
again, and leaving the status at `completed` would let an ineligible customer
through the loan gate in Phase 5.

Missing *documents* are deliberately not part of that checklist — the frontend
shows them as a warning, and blocking on them would make customers
loan-ineligible for a reason the UI never explains.

**The category is the rule engine.** `customer_categories.dynamic_form_schema`
is per-category and admin-editable, so no static Form Request can validate what
customers submit against it — the rules are data, so `DynamicFormValidator` is
too. It also drops keys the schema does not declare: the column is JSON, and
anything accepted would be stored verbatim and become indistinguishable from
real KYC data.

Editing a category's schema does **not** retroactively invalidate existing
customers' stored data. Theirs was valid under the schema in force when they
registered, and re-validating would mark long-standing customers KYC-incomplete
because an administrator added a field.

**Files never leave the private disk.** KYC documents and liveness captures go
to the `kyc` disk (`visibility: private`, `serve: false`, never symlinked into
`public/`). No response ever contains a storage path: `filePath` and
`photoPath` carry a **signed, 5-minute URL** to a download route instead. Those
two routes sit outside the Sanctum group because a browser navigating to an
`<img>` src cannot attach a bearer token — the signature is the credential,
which is exactly what spec §1 asks for. Filenames are generated, never taken
from the upload.

**Freezing is two facts in one transaction:** the customer's status and an
`account_freezes` row recording who, why and when. Unfreezing closes the open
row rather than deleting it, so the freeze history survives. `frozen` is
unreachable from the plain suspend/reactivate toggle.

### Money: fixed precision, never floats (Phase 5)

Every monetary amount flows through `App\Support\Money`, which holds an
**integer number of cents**; every rate flows through `App\Support\Percentage`,
which holds an integer number of thousandths of a percent (the 3 in
`DECIMAL(6,3)`). Neither accepts a PHP float — by the time a value is a float
the precision loss has already happened, and accepting it would launder that
error into the ledger.

`Money::allocate()` is the load-bearing piece: it splits an amount into parts
that sum **exactly** back to the original, distributing remainder cents one
each. That is what makes a repayment schedule's principal column sum to the
loan principal rather than to a cent less.

Rounding is half-up, matching MySQL's DECIMAL rounding *and* the frontend's
`round2()`, so a schedule computed here and one computed in the browser agree
to the cent.

**Resources emit decimal strings, not JSON numbers.** JSON has a single numeric
type — a double — so returning a bare number would hand the browser a float and
undo all of this.

### Loan origination (Phase 5)

**One implementation of every calculation.** `LoanScheduleGenerator` is the
single source of the three §6 formulas (SIMPLE / FLAT / REDUCING). Runtime
approval, the seeder and the tests all call it — there is no second copy of the
interest arithmetic anywhere, because two copies are two answers waiting to
disagree. It is deterministic: the start date is a parameter, not `now()`, so a
schedule can be regenerated for verification and will match what was stored.

**Snapshots protect live agreements.** `interest_rate_snapshot`,
`penalty_rate_snapshot` and `requires_mandate_snapshot` are taken at
application time (§6), so an administrator editing a product cannot rewrite
terms already agreed with a customer. A test pins this.

**The state machine is the only way a loan moves.** `LoanStateMachine` encodes
§10's transition table and writes the `loan_status_history` row every move
requires, so no action can invent a transition or forget to record one.

**Eligibility runs before anything is written** (§6) and returns *every*
violation, not just the first — an officer should see everything wrong with an
application at once. The gates: KYC complete, customer not frozen/suspended and
not awaiting or refused approval, at least one guarantor, product active,
category→product eligibility, schedule supported by the product, amount within
the category-capped range, tenure in range, no other open loan, and no
post-closure cooldown.

**Separation of duties (§14)** is enforced across five distinct grants —
`loans.view` / `create` / `approve` / `credit_review` / `disburse` — and the
officer who raised an application can never approve it. Cross-branch credit
review additionally needs the explicit `loans.review_cross_branch` grant, which
scope alone never implies (§13).

### The posting engine (Phase 6)

**`LedgerService::post()` is the only code path that writes a journal entry.**
No controller, model, observer or seeder inserts into `journal_entries` or
`journal_entry_lines` directly. Every financial event — disbursement,
repayment, suspense arrival, suspense resolution, capital injection, reversal —
builds its lines and hands them to the same method, which:

1. refuses fewer than two lines;
2. refuses any line whose amount is not positive (guarded in `JournalLine`
   itself, so an incoherent line cannot exist even momentarily);
3. refuses any account that is missing or inactive;
4. refuses anything where debits ≠ credits — **exactly**, not within a
   tolerance. Money is held in integer minor units, so there is no rounding
   noise for a tolerance to absorb and any difference is a real defect.

**The ledger is the single accounting source of truth.** `journal_entries` and
`journal_entry_lines` have no `deleted_at` and no post-insert mutation: both
models override `update()` and `delete()` to throw `ImmutableRecordException`,
so even a tinker session cannot quietly rewrite history (§8). The only way to
undo a posting is a reversal — a **new** mirrored entry with `is_reversal=true`
and `reversed_entry_id` set. The original's lines are never touched, and that
is what makes the pair auditable.

**`account_balances` is a cache and is treated as one.** It is *recomputed*
from the lines by `AccountResolver::refreshBalances()` after every posting,
never incremented — an incremented cache drifts, and a drifted cache is
indistinguishable from a real balance. The trial balance never reads it:
`TrialBalanceBuilder` re-aggregates `journal_entry_lines` on every call,
because a report that exists to *prove* the books balance must not be reading a
summary it cannot itself verify.

The cache is keyed `(account_id, branch_id)` — one row per branch that has
touched the account, plus a branch-less row for lines carrying no branch.
There is no separate account-wide row; `ChartOfAccount::cachedBalance()` sums
them, and `cachedBalanceFor($branchId)` reads one.

**§2.7's sub-ledgers are queries, not tables.** Customer, loan, staff and
branch ledgers are `journal_entry_lines` filtered on the matching dimension
column, which is why one endpoint (`GET /ledger/{dimension}/{id}`) serves all
four.

**Loan activation happens only after the posting succeeds.**
`SettleDisbursementAction` posts Dr Loan Receivable / Cr Principal **first**,
then transitions the loan, both inside one transaction. If the posting fails
the loan never becomes active — which is precisely §6's invariant, and the one
that would otherwise leave an `active` loan with no entry behind it.

### Repayments & collections (Phase 6)

**Three intake channels, one allocation core** (§7). The provider webhook, the
teller's cash entry and a Finance officer resolving a suspense item all funnel
into `RecordRepaymentAction::applyToLoan()`. They differ only in which account
is debited; the allocation, schedule updates, ledger posting and loan-status
consequences are written once.

**The allocation rule has exactly one implementation.** `PaymentAllocator` walks
the oldest unpaid installment first and applies **Penalty → Interest →
Principal** within each one before moving on (Decision 2, §7). It is pure — it
computes and returns, writing nothing — so the rule is unit-tested without a
database, and the caller persists the result alongside the posting in a single
transaction.

The canonical repayment posting (§5):

```
Dr Cash / Bank / Teller Cash        (what was received)
  Cr Penalty Income                 (penalty component)
  Cr Interest Income                (interest component)
  Cr Loan Receivable                (principal component)
Dr Interest Income                  (10% reserve cut)
  Cr Reserve Account
```

The reserve cut is two extra lines on the **same** entry, not a second entry:
it is not an independent event but part of recognising that interest. Netting
it against the income line instead would hide the gross interest the P&L needs.

**Nothing sits un-ledgered.** A payment that cannot be matched to a loan is
still posted the moment it arrives (Dr Cash/Bank · Cr Suspense) and gets a
`suspense_items` row; it is never dropped. Resolving it later posts a *second*
entry (Dr Suspense · Cr Loan), never an edit of the first.

**Overpayment is not absorbed.** The allocator caps every component by what is
actually outstanding, so a schedule row can never record more paid than due and
the excess surfaces as `unallocated` for Finance to decide on (§7).

**Cash carries two trust states.** A teller's entry is allocated and posted
immediately but lands on `pending_verification`, not `allocated` — §7 is
explicit that cash-in-hand and bank-confirmed cash are different things, and
the second only arrives when a deposit slip is reconciled.

**Duplicate protection is threefold** (§7): the UNIQUE index on
`payments.transaction_id`, an explicit check in `ReceiveInboundPaymentAction`,
and the `Idempotency-Key` header §1 mandates. Providers retry callbacks
routinely.

### The payroll engine (Phase 7)

**One implementation, three callers.** `PayrollCalculator` is the only place a
payslip is computed, and the runtime action, the seeder and the tests all call
it. It is pure — it computes and returns, writing no rows and posting nothing —
which is what lets a draft run exist at all. Every figure is `Money` (integer
minor units); nothing here is a float.

The line, as §11 defines it:

```
base salary
  + commission          (from the commission engine, if eligible)
  + allowances          transport 50,000 (branch staff only) + airtime 20,000
  − deductions          staff fund 10% of BASE + 50,000 per outstanding loan/advance
  = net salary
```

The fund contribution is a percentage of **base** salary, not of gross: a good
commission month does not increase what an employee contributes to the fund.
A net that goes negative is surfaced rather than clamped — an employee whose
recoveries outrun their salary is a real situation, and hiding it would pay
them money the deduction schedule says they do not have.

**§14's separation of duties is the shape of the module**, not a UI
convenience:

| Step | Grant | Who | Posts? |
|---|---|---|---|
| `POST /payroll/generate` | `payroll.generate` | HR | **No** — draft only |
| `POST /payroll/{run}/finalize` | `payroll.finalize` | Finance | Yes — recognition + deductions |
| `POST /payroll/{run}/pay` | `payroll.finalize` | Finance | Yes — settlement |

"HR can generate payroll but not finalize/pay it (Finance does)." The person
who computes what everyone is owed is not the person who releases the money,
and a draft can be examined, questioned and regenerated where a posted entry
cannot.

**Three entries per employee, and the separation is the point.** Each answers a
different question at a different moment:

```
1. Recognition (finalize)   Dr Salary Expense       base + allowances
                            Dr Commission Expense   commission
                              Cr Staff Payable      gross owed

2. Deductions   (finalize)  Dr Staff Payable        total deducted
                              Cr Staff Fund         the 10% contribution
                              Cr Staff Loan Receivable
                              Cr Staff Advance Receivable

3. Payment      (pay)       Dr Staff Payable        net salary
                              Cr Bank
```

Staff Payable is what makes them cohere: recognition credits it, deductions and
payment debit it, and once a run is paid it nets to **exactly zero** per
employee — a test pins that. Collapsing the three into one entry would erase
the period in which the company has recognised a debt it has not yet settled,
which is the entire reason a payable account exists.

**Five accounts beyond §5's eighteen.** §5's own canonical postings name Salary
Expense, Staff Payable and Commission Expense — accounts its table omits — and
§11 adds the staff loan and advance receivables. The frontend resolved the gap
by defining all five as system accounts with fixed codes (6000, 6100, 7010,
7020, 7050); those codes are reproduced exactly. Nothing was invented: the
postings that need them were always in the specification.

### The commission engine (Phase 7)

**Branch-performance-based, never individual-sales-based** (§11). Nobody earns
commission for closing a loan; a branch earns a pool for being profitable, and
its staff share it. That is a deliberate incentive design — it rewards a
branch's book quality rather than its origination volume — and it is why
`CommissionCalculator` never sees a loan.

```
hq hold             = branch profit × 2%
distributable       = branch profit − loss carried forward − hq hold
pool                = distributable × 20%     (zero if distributable ≤ 0)
each staff share    = pool × (their base salary ÷ total eligible base salary)
zone override       = 5% of the combined pools of the zone's branches
```

The order matters and is §11's: HQ takes its cut of gross profit **first**, then
the carried-forward loss is offset. Taking the hold afterwards would let a
loss-making branch shrink HQ's share.

**A loss-making branch produces a pool of exactly zero.** §11's hard rule —
"commission_distributions for a branch/period cannot be created while
distributable_profit <= 0 (loss must be offset first)" — is enforced in the
service, not merely hidden in the UI. The pool *row* is still written, because
the loss itself is information the next period needs; not one shilling of
distribution is. Note that zero is not positive: a branch that broke exactly
even shares nothing.

**Branch profit is read off the ledger**, not typed in. §8 defines it as income
minus expense per branch and §12 makes every branch report a filtered query
over `journal_entry_lines.branch_id`, which is exactly what
`BranchProfitCalculator` does. A manager asking why the pool is what it is can
be shown the entries behind it. (§8's "Reserve already netted out" needs no
special handling: §5 posts the reserve cut against Interest Income on the
collection entry, so the income account is already net of it.)

**Commission posts nothing.** A pool is an entitlement, not a transaction — no
money moves when a branch earns the right to share out its profit. It reaches
the books exactly once, as Commission Expense on the recipient's payroll
recognition entry. A zone override is folded into that same manager's line and
`zone_commission_distributions.journal_entry_id` points at it, rather than
posting a second entry for money already recognised. A test asserts that total
commission expensed equals total commission awarded, and that no entry has
`source_type = commission` at all.

**Shares are rounded independently**, as the frontend rounds them: three equal
shares of 100.00 come to 33.33 each, summing to 99.99. That is harmless — a
pool is a computed entitlement rather than cash waiting to be emptied out of an
account, and each share is expensed on its own balanced entry. It is pinned by
a test so that switching to `Money::allocate()`, which would force an exact
sum, stays a deliberate choice.

### Reporting (Phase 8)

**Twenty-four reports, and not one of them stores anything.** There is no
reporting table, no nightly rollup and no report-only column anywhere in the
schema. Every report recomputes from the operational tables and the ledger on
each call, which is what §15.6 means by numbers being "traceable to a specific
computation timestamp" — `meta.generated_at` is the moment of the computation,
not of a cache. A test proves it: take a payment, and the portfolio report's
outstanding drops by that amount on the next call, with nothing to invalidate.

**One accessor layer.** `ReportSources` is the only place a report reads from.
Two reports over the same data cannot disagree because one forgot a
`deleted_at` check or bucketed a loan a day differently — there is one
definition of "open-book loan", one of "outstanding", one of "days past due",
and `DpdBucket` holds the 0 / 1–7 / 8–30 / 30+ boundaries and the A/B/C/D
scoring that reads off them.

**Every report states its own provenance.** `reconciliation` is a required
field, not decoration: a figure is only traceable if the reader is told where
it came from. Where a report *should* tie to the ledger it says so and, in the
Suspense report's case, computes both figures and reports whether they actually
agree rather than asserting that they do.

**What reconciles to what** — all verified by test and re-verified live:

| Figure | Ties to |
|---|---|
| Financial Statements totals | Trial Balance, and `GET /ledger/trial-balance` — one computation, three doors |
| Age Analysis outstanding | Loan Portfolio outstanding (every loan in exactly one bucket) |
| Segmentation outstanding | Loan Portfolio outstanding |
| Daily Collection | Repayments, over the same filters |
| Repayments → interest | Interest Income credits |
| Repayments → principal | Loan Receivable credits |
| Daily Disbursement | Loan Receivable debits |
| Payroll → commission | Commission Expense debits |
| Payroll → base + allowances | Salary Expense debits |
| Branch P&L profit | `BranchProfitCalculator` — the figure the commission pool was struck from |
| Branch Ranking / Efficiency | Branch P&L |
| HQ Cashflow | Cashflow scoped to `is_head_office` (§12: one definition, not two engines) |
| Open Suspense | Suspense Account balance |
| Executive Summary | Every line lifted verbatim from the source report it names |

Two relationships are deliberately **not** equalities, and the reports say so:

- **Portfolio outstanding vs Loan Receivable.** The account carries principal
  only — §5 debits it at disbursement and credits the principal component of
  each repayment. Outstanding is principal *plus* unpaid interest and
  penalties, which the ledger does not carry as a receivable because interest
  is recognised on collection. The invariant that holds is `outstanding ≥
  principal on the books`, and that is what the test asserts.
- **Branch P&L vs the Commission report's Branch Profit.** The commission
  figure is the profit *as at pool generation*; §11 sequences close →
  commission → payroll, so the period's salary expense lands after the pool is
  struck and the live P&L is legitimately smaller. Both reports carry the
  explanation.

**Branch scope is enforced on reports too** (§13). A user without
`branches.view_all` is pinned to their own branch whatever the query string
asks for — a report must not be a way around the scoping every other endpoint
enforces. A report that does not slice by branch (the audit trail, the trial
balance) is left alone, because a company's trial balance is not a branch's.

**Filters are declared, not assumed.** Each report names which of
`branch_id` / `period` / `from` / `to` it honours, and anything else is dropped
before `meta.filters_applied` is built — so the echo never claims a window the
figures ignored. Zone Commission declares `period` alone; sending a branch as
well changes nothing and says nothing.

**One route, twenty-four reports.** §15.6 lists paths of the form
`/reports/<name>`, and `/reports/{slug}` *is* exactly those paths — twenty-four
near-identical controller methods differing only in which object they called
would be worse. `GET /reports` publishes the catalogue from the same registry
the resolver uses, so the index and the API can never disagree about what
exists.

### Rate limiting

| Limiter | Applies to | Budget |
|---|---|---|
| `auth` | login, change-password | 5/min per phone+IP, 20/min per IP |
| `password-reset` | forgot/reset password | 3 per 15 min per email, 10 per 15 min per IP |
| `api` | everything else | 120/min per user, 30/min per unauthenticated IP |

Keying on phone+IP rather than IP alone means hammering one account cannot lock
out unrelated users behind the same NAT, while rotating IPs cannot bypass the
per-account limit.

### Conventions established in Phase 1

- **API-only.** No Blade views, no Vite/npm. The `ForceJsonResponse`
  middleware sets `Accept: application/json` on every API request, so even a
  client that forgets the header gets JSON for validation errors and uncaught
  exceptions rather than an HTML error page.
- **Immutable dates.** `CarbonImmutable` is the default via `Date::use()`,
  preventing the classic accounting bug where a shared date instance is
  mutated while building a repayment schedule or a period range.
- **Strict models.** `Model::shouldBeStrict()` outside production — lazy
  loading, silently discarded attributes and missing attributes all throw. In
  a double-entry system these are correctness bugs, not style issues.
- **Destructive commands prohibited in production.** `migrate:fresh` and
  `db:wipe` are blocked outright; the ledger is append-only.
- **Explicit CORS.** An allow-list from `CORS_ALLOWED_ORIGINS`, never `*`.
  `supports_credentials` is `false` because Sanctum runs in token mode.
- **Private KYC storage.** The `kyc` disk is `visibility: private`,
  `serve: false`, and is never symlinked into `public/`. NIDA photos, liveness
  captures, deposit slips and payslips are served only via temporary signed
  URLs.

### Queues

Four named queues, configured in `config/queue.php` under `names` and
referenced as `config('queue.names.ledger')` rather than as hardcoded strings:

| Queue | Carries |
|---|---|
| `ledger` | Ledger-affecting side effects |
| `notifications` | SMS / mail fan-out |
| `reports` | Report and risk-score recomputation |
| `default` | Everything else |

A slow SMS gateway must never delay a disbursement response — hence the split.

---

## Testing

Pest, running against a **real MySQL schema** (`mikopofasta_test`), not
SQLite `:memory:`. This is deliberate: the ledger depends on `DECIMAL(18,2)`
arithmetic, `ENUM` columns and `JSON` columns whose semantics differ between
the two engines. Testing on SQLite would let money-handling bugs pass green.

Feature tests use `RefreshDatabase` (transaction-wrapped), configured in
`tests/Pest.php`.

### Static analysis

PHPStan runs at **level 6** with `checkModelProperties: true`, so a typo in a
model attribute name is a build failure rather than a silent `null`.

`tests/` is excluded from analysis: Pest binds test closures to
`Tests\TestCase` at runtime via `pest()->extend()`, which PHPStan cannot see.
Including them would require blanket-ignoring `method.notFound` across the
directory, which would also mask genuine typos. The suite is verified by
running it.

Raise to level 8 once the domain models exist and their return types settle.

---

## Production Readiness (Phase 9)

The four blockers from the readiness audit, closed. No business feature was
added, no domain logic redesigned, no financial calculation touched, and no OSC
resolved — verified by re-running every reconciliation afterwards: **18/18
pass, trial balance 240,487,476.68 on both sides, zero unbalanced entries.**

### B1 — Penalty scheduler

`penalty:apply` runs daily at 00:05 and calls the same
`RunOverdueProcessAction` the manual endpoint uses, so the cron and the Finance
button cannot diverge. It is `withoutOverlapping(expiresAt: 60)` — two runs at
once would each top up the same penalties on figures the other had moved — and
`onOneServer()`, so a fleet does not fire it once per host. The expiry matters
as much as the lock: without it a killed run would hold the lock forever.

The run is attributed to no user. A scheduler is not a person, and
`penalty_runs.triggered_by` records `cron` instead. Failures are logged and
re-thrown rather than swallowed, because penalties quietly ceasing to accrue is
the kind of fault nobody notices until a borrower disputes an arrears figure.

OSC-1 and OSC-4 are unchanged: accrual still posts nothing, and the top-up
behaviour is exactly as documented.

### B2 — Webhook signature verification

`VerifyWebhookSignature` is route middleware on both callbacks, running before
the Form Request and before the controller — a test proves it by sending a
payload that validation would reject and asserting 401 rather than 422.

The signature covers `{timestamp}.{raw body}`, compared with `hash_equals` so a
guess cannot be recovered byte by byte through timing. Every failure — missing,
malformed, mismatched, stale — returns the same 401 body; distinguishing them
would tell an attacker which half of the check they had passed. The reason is
logged for the operator instead.

**An unconfigured secret rejects rather than waves through.** A deployment that
forgot to set one fails closed, because treating "no secret" as "no
verification required" is precisely how an endpoint that posts to the ledger
ends up open to the internet.

### B3 — Idempotency middleware

`EnsureIdempotency` implements §1's "hash of (key + endpoint) for 24h, replay
the original response". It is applied to the five money-moving endpoints: cash
repayments, both webhooks, payroll generation and payroll payment.

The fingerprint includes the request BODY as well as the key and route, so a
key reused for different data is a 409 rather than a silent replay of the wrong
response — a client doing that has a bug, and hiding it would acknowledge a
payment that was never taken. A Redis lock reserves the fingerprint before the
request runs, so two callbacks arriving in the same millisecond cannot both
execute; the second waits and replays.

Only 2xx responses are stored. Replaying a 500 for twenty-four hours would make
a transient database blip permanent.

Replays carry `Idempotent-Replay: true`, without which a caller could not tell
a replay from a fresh success and would never notice it had retried.

### Safe hardening

**Operational logging** on a dedicated `operations` channel with 90-day
retention — separate from the 14-day application log because the events that
explain money movement must not rotate away on the same cycle as framework
deprecations. It records webhooks received and rejected, ledger postings
refused (at `critical`), scheduler runs, payroll generation/finalization/
payment, disbursement settlement and every repayment. A test asserts the
negative too: no signature, secret or transaction id reaches the log.

**Audit coverage** for guarantors and next-of-kin. Removal snapshots who the
person was *before* the delete, because §6 requires at least one guarantor
before a loan may progress — removing one changes what a customer is eligible
for, and the row is gone by the time anyone asks.

**`actor()` moved to the base `Controller`**, removing 21 identical private
copies. Pure refactor; no behaviour change.

### What this phase deliberately did not do

Treasury, month-end close, bank reconciliation, write-off and recovery
postings, new reports, new endpoints, new business rules, and OSC-1 through
OSC-7 all remain exactly as documented below.

---

## Backend Readiness Report

Audited after Phase 8, with the codebase feature-complete. **508 tests / 2,715
assertions passing, PHPStan level 6 clean, Pint clean.** One correctness defect
was found and fixed; everything else below is reported, not changed.

### Verdict

**Ready for a production pilot once the four blockers below are closed.** The
money paths are sound — every posting is transactional, every financial report
reconciles to the ledger, and no N+1 exists anywhere. The gaps are operational
wiring, not correctness.

### Blockers before go-live — ALL CLOSED IN PHASE 9

| # | Area | Finding (as audited) | Status |
|---|---|---|---|
| B1 | Queue safety | **The penalty accrual job is never scheduled.** §7 specifies a `penalty:apply` cron; `POST /loans/overdue/process` implements it, but `routes/console.php` schedules nothing. In production no penalty would ever accrue unless a human pressed the button. There are also no queued jobs at all, though `QUEUE_CONNECTION=redis` is configured — everything runs synchronously, which is safe for a ledger but means the §1 queues (`ledger`, `notifications`, `reports`) do not exist. |
| B2 | Security | **Neither webhook verifies a signature.** §1 requires an HMAC header (`X-Vodacom-Signature`, `X-Bank-Signature`); `routes/webhooks.php` documents the omission but both endpoints currently accept any caller. `POST /webhooks/payments` can create payments and post to the ledger; `POST /webhooks/vodacom/disbursement-status` can activate a loan. Protected today only by their own idempotency and the 30/min anonymous rate limit. |
| B3 | Configuration | **Idempotency middleware is not built.** §1 requires `Idempotency-Key` on every money-moving endpoint with a 24h replay window. Duplicate protection today is per-record (UNIQUE `transaction_id`, `payroll_runs.period`, batch/advance status markers), which covers the known paths but not a retried `POST /payments/cash`. |
| B4 | Open decisions | **Seven Open Specification Conflicts remain unresolved** (OSC-1…OSC-7 below). Each is documented with the implemented reading and its alternative; four affect money (penalty recognition, the penalty base, the loss carry-forward, the staff-fund posting) and need a business decision, not a code change. |

### Fixed during this audit

**Non-atomic staff update** — `PUT /staff/{staffProfile}` performed three
writes (profile, bank details, audit row) outside a transaction, so a failure
on the bank details would have left a salary changed with no audit row
recording it. Every other write path in the codebase is transactional; this one
now is too. Behaviour on the success path is unchanged.

### Clean — verified, no action

| Area | Evidence |
|---|---|
| Transactions | Every one of the 52 domain actions that writes opens a transaction. All five `LedgerService::post()` call sites are transactional, and `post()` opens its own. |
| N+1 queries | Query-count guards over 15 collection endpoints and all 24 reports. Payment list cost is identical before and after adding rows — the definition of no N+1. `Model::automaticallyEagerLoadRelationships()` is on. |
| Database indexes | 51 of 51 filtered/joined columns carry a leading-column index. Zero missing. |
| SQL injection | All 10 raw-SQL sites are parameterised; none interpolates a variable into the SQL string. |
| Authorization | Every controller method has an explicit check except the six that are deliberately public (auth, password reset) and the two signed-URL file routes. `Gate::before` grants Super Admin everything; 16 policies are registered explicitly because they live outside `app/Policies`. |
| Branch scope | §13 enforced on reports too — a user without `branches.view_all` is pinned to their own branch whatever the query string asks for. |
| KYC file access | Private disk, 5-minute signed URLs minted only inside an authorized resource, `no-store` and a sandbox CSP on the response. |
| Mass assignment | Every application model declares `$fillable`; none uses `$guarded`. `password` is `hashed`-cast and `$hidden`. |
| CORS | Explicit origin allow-list from env, no wildcard, `supports_credentials: false`, enumerated headers. |
| Production guards | `shouldBeStrict` outside production, `prohibitDestructiveCommands` in it, `URL::forceScheme('https')`, `CarbonImmutable` globally, `.env` gitignored with no secrets committed. |
| Rate limiting | Login 5/min per phone+IP and 20/min per IP; password reset 3 per 15 min; API 120/min authenticated, 30/min anonymous. |
| Exception handling | One renderer maps 8 exception classes to the §1 envelope. Unhandled failures fall through to Laravel's default 500, which carries no `error_code` — deliberate, since an unexpected fault has no meaningful business code. |
| API consistency | Zero controllers bypass `ApiResponse`. Every resource id is a string, every key camelCase, every Form Request declares `authorize()`. |
| Contract compliance | Every field of 27 frontend Zod schemas verified present across 17 endpoints plus 4 nested record types; every id a string; every money field a `^-?\d+\.\d{2}$` string; envelope and status codes (200/201/401/403/404/405/409/422) asserted. |
| Audit logging | 72 of 76 `AuditAction` cases are emitted. The 4 unused belong to the deferred bank-reconciliation workflow. |
| Memory | 48.5 MB peak to compute all 24 reports (6 MB delta) against a 512 MB limit. |

### Performance findings — reported, not changed

These are behaviour-preserving optimisations, ready to apply on approval.

**P1 — Nine non-sargable date predicates.** `whereDate()` and
`DATE_FORMAT(...)` wrap an indexed column in a function, so MySQL cannot use
the index. They sit on the two tables that grow without bound:
`journal_entries.entry_date` (6 sites) and `audit_logs.created_at` (3 sites).
On a million-row audit table a period-filtered Audit Trail is a full scan.

*Remediation:* for the DATE column `entry_date`, `whereDate('entry_date','>=',$x)`
becomes `where('entry_date','>=',$x)` — identical results, index-eligible. For
the TIMESTAMP `created_at` the rewrite must be a half-open range
(`>= $from 00:00:00` and `< $to +1 day`), because a naive `<= $to` would
silently exclude most of the final day. Sites:
`ReportSources::journalLines`, `AuditTrailReport::compute`,
`TrialBalanceBuilder::build`, `LedgerController::entries`.

**P2 — Per-branch reports are O(branches), not O(rows).** Branch Ranking runs
one trial balance plus one portfolio read per branch (36 queries at 5 branches,
~360 at 50). Branch P&L and Branch Efficiency have the same shape. Correct at
current scale and budgeted by test; worth a single grouped query before the
branch count grows.

**P3 — Webhooks share the anonymous 30/min per-IP limit.** A provider sending a
burst of callbacks from one IP would receive 429s. Webhooks should have their
own limiter sized to the provider's throughput once B2's signature check
identifies the caller.

### Maintainability findings — reported, not changed

**M1 — `private function actor()` is duplicated in 21 of 26 controllers**
(~150 identical lines). It belongs on the base `Controller` alongside
`AuthorizesRequests`. Pure refactor, no behaviour change.

**M2 — Dead code, all traceable to documented deferrals.** Unused: models
`CashDeposit` and `LoanTopup` (§2.6/§2.5 tables whose workflows are deferred);
resources `AccountFreezeResource`, `CustomerBankDetailResource`,
`EMandateResource`; exceptions `PasswordResetUnavailableException` and
`CommissionException`; method `ReportSources::balanceOfType()`. Note that
`CommissionException` is never thrown because §11's loss rule is enforced by
producing a zero pool rather than by raising — the rule is tested and holds;
the exception is simply redundant.

**M3 — No operational logging.** Three `Log::` calls exist in the entire
codebase. Audit logging is comprehensive, but there is no operational trace of
webhook receipt, provider failures or posting rejections — the things an
on-call engineer reads at 3am. `UnbalancedEntryException` in particular is a
500 that leaves no log line.

**M4 — Guarantor and next-of-kin changes are not audited.**
`ManageCustomerRelationsAction` is the only writing action with no audit row.
Removing a guarantor changes loan eligibility (§6 requires at least one), so it
is a decision worth recording.

---

## Roadmap

Phases 1–8 are complete — the backend build is finished. Deliberately **not**
yet built:

| Item | Notes |
|---|---|
| Idempotency middleware | Spec §1: `Idempotency-Key` on every money-moving endpoint, 24h replay window. The header is already allow-listed in CORS. Phases 6–7 rely on UNIQUE indexes and per-record status markers instead (`payments.transaction_id`, `payroll_runs.period`, batch and advance statuses); the middleware generalises that and is worth building before more providers arrive. |
| Bank reconciliation | `POST /finance/bank-reconciliation` (§15.3) and the `cash_deposits` workflow that moves a cash payment from `pending_verification` to `allocated`. The table exists; the matching endpoint does not. |
| Month-end close & profit | §8's close job (Dr Income · Cr Profit). Phase 7's commission engine computes branch profit directly from the ledger, so it does not depend on the close having run — but the Profit Account posting itself is still outstanding. |
| Treasury | §2.8 — `capital_contributions` and `dividends`. §5 defines the postings; no phase has covered the module. Capital is injected by the seeder through LedgerService today. |
| Write-off & recovery postings | §5 defines Dr Write-Off Expense · Cr Loan Receivable and Dr Cash · Cr Recovered Loans; the arrears transitions that trigger them are unbuilt, so the Recovery report lists loan states rather than ledger balances. |

### Open Specification Conflicts

**OSC-2 — `loan_products.penalty_rate` cannot be `DECIMAL(6,3)`.**
§2.3 types the column `DECIMAL(6,3)` *and* says its "meaning depends on
penalty_type (% or flat amount)". Both cannot hold: `DECIMAL(6,3)` caps at
999.999, while the frontend's own seed gives the Salary Advance product a
`flat_fee` penaltyRate of **10,000 TZS**.

Resolved by widening to `DECIMAL(18,3)`, which keeps the spec's single-column
design (a second column would redesign the entity) and stores both readings
losslessly. `LoanProduct::penaltyRate()` and `penaltyFlatAmount()` expose the
two readings so no caller has to remember which it holds. **Needs a decision
before go-live:** either ratify the widening, or split the column in the spec.

**OSC-3 — `loan_products.interest_rate` has no stated period.**
For FLAT and REDUCING the rate is charged **once per installment**, per the
frontend's documented formula in `lib/domain/loan-schedule.ts`. The
specification names the three formulas but never says whether `interest_rate`
is a per-period or a per-annum figure, and the frontend states plainly that its
maths are "the domain layer's own documented assumptions ... reasonable
defaults, not a claim of the one true formula".

The consequence is only visible at short cadences. A 400,000 loan at 8% over 90
**daily** installments totals 1,855,999 payable, because 8% is applied ninety
times. The same product on a monthly cadence behaves as expected.

The implementation is faithful to the contract and is **deliberately
unchanged**. What is needed is a business decision: either `interest_rate` is
per-period (current behaviour, and daily products must be priced accordingly),
or it is per-annum and the generator must pro-rate it by
`frequency_days / 365`. That is a pricing decision, not a code cleanup.

**OSC-1 — §7's penalty-accrual posting cannot be made.**
§7 says the overdue job should post "Dr Loan Arrears / Cr Expected Schedule".
That instruction cannot be followed as written:

1. "Expected Schedule" is not one of the accounts §5 defines. There is no such
   row in the chart, and inventing one would be inventing an accounting policy.
2. §5 already recognises penalty income when a penalty is **collected**
   (Cr Penalty Income on repayment). Posting again on accrual would
   double-count it — every penalty would appear twice in the P&L.

`RunOverdueProcessAction` therefore posts **nothing**. The accrued penalty
lives on `loan_schedules.penalty_due` and in `penalty_runs`, and reaches the
ledger exactly once, on collection. The absence is stated in the endpoint's own
response (`ledgerPosting`) and in every audit row, so it reads as a decision
rather than an omission. **Needs a decision before go-live:** ratify
collection-basis recognition, or move to accrual — which requires a real contra
account added to §5 *and* the collection posting changed to clear a receivable
instead of recognising income.

**OSC-4 — the penalty base is not specified, so a repeated run compounds.**
The frontend's overdue job comments that "re-running the job must not stack
penalties on the same installment", and both implementations honour that
literally: the computed figure is topped up to, never added to. But
`PenaltyCalculator` takes its base from the installment's **outstanding
total**, which includes the penalty already accrued — so a second run on the
same day computes a slightly larger figure and tops up again. §7 does not say
whether a penalty may be charged on an unpaid penalty.

The behaviour is faithful to the frontend and **deliberately unchanged**. It is
pinned by a named test ("it grows on a repeated run because the base includes
the accrued penalty") so that changing the base is a deliberate decision that
breaks a test, rather than a silent change to what every borrower owes.
**Needs a decision before go-live:** either the base excludes accrued penalty
(and the job becomes genuinely idempotent), or compounding is intended and the
cron cadence must be fixed at once per day.

**OSC-5 — `loss_carry_forward` has no defined source.**
§2.9 stores the column and §11 makes it decisive ("loss must be offset first"),
but neither says how the figure is produced. The frontend hardcodes it in its
seed data, which answers nothing.

The reading implemented is the literal one: a period whose distributable profit
went negative carries that shortfall into the next period, and carries nothing
forward once it has been cleared. It follows from what "carry forward" means
and from §11's insistence that a loss be offset, and it is the only reading
under which the rule ever stops applying.

**Needs a decision before go-live:** ratify the automatic carry-forward, or
make it a figure Finance sets explicitly at month-end close — which would let a
loss be written off deliberately rather than pursued indefinitely. Both are
defensible; the specification implies the first without saying so.

**OSC-6 — a staff loan or advance is disbursed without any cash moving.**
The posting the frontend defines, and which is therefore implemented, is:

```
Dr Staff Advance Receivable
  Cr Staff Fund Account
```

No cash or bank account is touched, so the books record an employee owing the
company money that the books never show leaving it. §5 defers the postings to
§11 ("Staff Fund contributions/loans/advances: as specified in §11") and §11
never gives them, so there is no specified alternative to follow.

The reading this encodes is coherent — the Staff Fund is a liability funded by
employee contributions, and lending from it converts part of that liability
into a receivable — but it only holds if the fund is notional rather than a
real pot of money. If the fund is actually banked, the disbursement should
credit a bank account and the fund liability should be debited instead.

**Needs a decision before go-live:** confirm the Staff Fund is a notional
liability (current behaviour is correct), or bank it and re-specify the
disbursement posting. The recovery side needs no change either way — the
payroll deduction credits the receivable, which is right under both readings.

**OSC-7 — "confirmed" means something different in the two codebases.**
The frontend's collections reports filter payments on `status === 'confirmed'`,
and its mock payments are received and confirmed in a single step. This
backend's lifecycle does not collapse the two: §7 makes teller cash-in-hand and
bank-confirmed cash different trust states, so a provider payment settles at
`allocated` and a cash payment waits at `pending_verification` until a deposit
slip is reconciled. Nothing reaches `confirmed` at all until
`POST /finance/bank-reconciliation` ships.

Filtering literally on `confirmed` would therefore make the Repayments, Daily
Collection and Executive Summary reports permanently empty while the ledger
showed real interest income — a report contradicting the books, which is the
one thing Phase 8 requires must not happen.

`ReportSources::collectedPayments()` anchors on the ledger instead: a payment
that produced a journal entry, is matched to a loan, and has not been reversed
or flagged as a duplicate. That is exactly "money collected and posted", it
includes teller cash (which genuinely sits in Teller Cash in the books), and it
reconciles to the ledger to the cent.

**Needs a decision before go-live:** either ratify the ledger-anchored
definition, or ship bank reconciliation and narrow the filter to `confirmed` —
in which case cash collections will not appear in these reports until a deposit
slip is matched, which is a real business choice about what "collected" means
rather than a technical one.

### Phase 8 notes and deferred items

**Three reports beyond §15.6's twenty-one.** Phase 8's task list names Trial
Balance, Performance and an Executive Dashboard; §15.6's list has none of them.
None is an invention:

- **Trial Balance** is the computation Financial Statements and
  `GET /ledger/trial-balance` already run, published on its own so a reader can
  see the raw ledger position without the balance-sheet subtotals.
- **Staff Performance** reads §2.9's `staff_performance_records`. The
  achievement rate is the mean of achieved ÷ target across the review's
  metrics — the same derivation the frontend uses to pick a rating in its seed.
- **Executive Summary** invents no metric at all. The frontend's `/dashboard`
  is explicitly a foundation shell ("business modules land in their own
  implementation phases") and defines no KPIs, so every line is lifted verbatim
  from another report's own summary, with a `source` column naming which. A
  test asserts the strings are identical, so drilling in always shows the same
  number because it *is* the same number.

**Portfolio at Risk is not a separate report.** Phase 8 names it; §15.6 does
not, and the frontend headlines it on Age Analysis. It is published there as
`Portfolio at Risk (8+ days)` plus a `PAR Ratio`, on the 8-day boundary the
frontend chose. Splitting it out would be a second definition of the same
number.

**`generated_at` and `filters_applied` are snake_case.** Every other resource
attribute in this API is camelCase; these two are quoted verbatim in §15.6 and
are emitted exactly as the specification writes them rather than silently
renamed. The rest of a report's `meta` — `columns`, `totals`, `summary`,
`reconciliation`, `report` — follows the house camelCase.

**Reports are not paginated.** §15.6 gives them `?branch_id=&period=&from=&to=`
and no `page`, and a report is filtered rather than paged through. The Audit
Trail is the one exception in spirit: it caps at the newest 500 rows and says
so in both the summary and the reconciliation note, because an audit trail is
scanned for a window and returning a hundred thousand rows would help nobody.

**The demo book shows negative branch profits, correctly.** The seeded month
carries a full payroll (16.7M of salary expense) against a few days of interest
income. That is arithmetic, not a defect — and it is the §11 ordering working
as specified: commission was computed from the profit *before* payroll posted
into the same period.

**No report writes anything.** There is no POST, no export job and no
materialised table. `ReportPolicy` is registered as a Gate ability rather than
against a model, because a report spans loans, payments, payroll and the
ledger — binding it to any one model would silently import that model's
permission. (It was briefly bound to `JournalEntry`, which required
`ledger.view` and locked out every role §14 grants `reports.view` to; a branch
scope test caught it.)

### Phase 7 notes and deferred items

**There is no separate payroll "approval" step.** The task list named approval
and finalization separately, but the contract has three states — `draft` →
`finalized` → `paid` — and finalization *is* the approval: it is the moment
Finance accepts what HR computed and commits it to the books. Adding a fourth
state would invent a workflow the frontend has no screen for and §11 does not
describe.

**No endpoint creates a staff loan.** §2.9 defines `staff_loans` and §11 says
an advance and a loan both "mirror the customer loan engine internally", but
neither gives a staff loan its terms — no interest, no tenure, no schedule,
nothing a loan needs. The frontend seeds one and never creates another. So the
table, the model, the recovery deduction and the read endpoint are all
implemented; the origination workflow is not, because its rules would have to
be invented. Advances, which §11 *does* specify end to end, are complete.

**Recovery is a flat 50,000 per period, not an amortisation.** §11 says an
advance is "recovered automatically from payroll" and gives no schedule; the
frontend picked a flat figure. Deriving an amortisation would be inventing a
lending policy. The consequence is that a large advance takes many periods to
clear, and that a `recovered` status is never reached automatically — closing
one out is a manual act until a repayment rule is specified.

**Performance records have no bearing on pay, deliberately.** §11 computes
commission from branch profit and payroll from base salary; neither reads a
rating. Wiring performance into pay would be inventing an incentive scheme the
specification does not have. A test pins this: a "D" rating changes no figure
on the payslip.

**HR is not branch-scoped.** Unlike customers, loans and payments, nothing in
this module applies `BranchScope`. §14 scopes HR and Finance to all branches,
and a company keeps one personnel record per employee rather than one per
branch. A Branch Manager can record a review for a staff member (§11 gives
performance to managers) but cannot read the staff book.

**`zone_commission_distributions.journal_entry_id` is nullable** where §2.9
types it NN. The override is expensed as part of the zone manager's payroll
recognition entry, and that entry does not exist until Finance finalizes the
run — so the column is populated at finalization. The alternative would be a
second journal entry for money already recognised, which is precisely what the
frontend avoids.

**The payroll run has no `paid_at`.** §2.9 gives `payroll_runs` a
`finalized_at` and nothing else, and the frontend's `PayrollRunSchema` matches.
When a run was paid is recoverable from its payment entries' `posted_at`.

### Phase 6 notes and deferred items

**No endpoint posts a journal entry.** There is deliberately no `create`
ability on `LedgerPolicy` and no `POST /ledger/entries`. An entry is a
consequence of a business event; an API that let a user hand-write one would
make the ledger something other than a record of what happened.

**Bank reconciliation is not built.** §15.3's `POST /finance/bank-reconciliation`
needs a bank-statement import format that neither the spec nor the frontend
defines. `cash_deposits` exists because §2.6 defines it, and
`repayments.reconcile` exists as a distinct grant, but the matching workflow
would be invented rather than implemented. Cash payments consequently stay on
`pending_verification`, which is the honest state for them.

**Overpayment surfaces but is not routed.** §7 says the excess should go to "a
customer wallet/advance credit ... pending a refund-or-apply decision by
Finance". No such entity is defined anywhere — not in §2, not in the frontend —
so the allocator returns the unallocated remainder and the payment records the
full amount, without inventing a wallet.

**Write-off, recovery and default postings are not built.** §5 defines them and
the accounts exist in the chart, but the loan lifecycle transitions that
trigger them (`defaulted` → `written_off` / `recovered`) belong to arrears
management, which no phase has yet covered.

**The disbursement callback is reachable two ways.**
`POST /webhooks/vodacom/disbursement-status` (§15.2, the production path) and
the authenticated `POST /loans/{loan}/settle-disbursement` that the frontend's
loan actions panel calls. Both funnel into `SettleDisbursementAction`, so there
is one place a loan becomes active and one place it is posted. The webhook is
not yet HMAC-verified — that middleware arrives with the provider integration
— and is guarded meanwhile only by the batch's own idempotency.

### Phase 5 notes and deferred items

**Disbursement stops at preparation, deliberately.** §6 describes disbursement
as hybrid automated+manual: the system prepares a batch and calls the provider,
but the **callback** is what flips the batch to success and, per §6, "no ledger
entry exists until a disbursement batch reaches success". The ledger is Phase 6,
so settling a batch here would either activate a loan with no ledger entry
behind it or duplicate posting logic that belongs in `LedgerService`.
`prepare-disbursement` and `retry-disbursement` (with §6's 3-attempt cap and
escalation) are complete; `POST /webhooks/vodacom/disbursement-status` and loan
activation arrive with the ledger. No seeded loan is `active` for the same
reason.

**No fees or charges entity exists to build.** The task listed fees and charges,
but neither spec §2 nor the frontend defines any fee configuration — §5 has a
Fee Income account (2100) and nothing else. Implementing one would mean
inventing its rules. Interest and penalty are implemented in full
(`LoanScheduleGenerator`, `PenaltyCalculator`).

**The guarantor minimum is a documented constant, not configuration.** Neither
§6 nor the frontend defines a configurable minimum — the wizard permits an
empty guarantor list and `loan_products` has no guarantor column. The gate is
`LoanEligibilityChecker::MINIMUM_GUARANTORS = 1`. Making it per-product is a
schema change and a specification decision, not a default to guess at.

**Top-up is read-only.** `GET /loans/{id}/topup-eligibility` (§15.2) is
implemented; granting a top-up is not, because how the outstanding balance
rolls into the new principal is not specified anywhere. `loan_topups` exists
because §2.5 defines it.

**`PenaltyCalculator` is written but not yet invoked.** It is the §7 overdue
job's calculation, and that job belongs to Repayments (Phase 6). It lives here
because it reads only product configuration, and building it twice is exactly
what this phase set out to avoid.

### Phase 4 notes and deferred items

**Groups are backend support only.** Spec §2.4 defines `groups` and
`group_members`, and the customer profile shows membership read-only, so the
tables, models and seed exist. There are deliberately **no group CRUD
endpoints** — the frontend has no group management screens (readiness report
gap 1) and inventing the module would mean inventing its business rules.

**`customer_risk_scores` (§2.10) is not built.** It is a derived read-model
recomputed by a queued job for the Repayment Behaviour report, and it depends
on loan and payment history that does not exist yet. It belongs with Reports.

**Branch delete guards are now partly closed.** `DeleteBranchAction` still
guards Head Office, sub-branches and users; the customer guard can be added now
that the table exists, but loans arrive in Phase 5, so it is left for that phase
to close both at once. The FK is RESTRICT regardless.

### Phase 3 notes and deferred items

**Branch delete guards are partial by necessity.** The frontend refuses to
delete a branch that has customers or loans on record. Those tables arrive in
Phases 4–5, so `DeleteBranchAction` currently guards Head Office, sub-branches
and assigned users; the customer and loan guards are added with those modules.
Every FK is RESTRICT regardless, so the database is the backstop until then.

**HR sees only its home branch in the branch list.** §14's role table describes
HR's scope as "All branches", but the frontend's permission matrix does not
grant HR `branches.view_all`, and the frontend is the contract. HR's authority
over staff is carried by `hr.view` / `hr.manage`, not by branch visibility, so
nothing in Phase 3 is affected. When the HR module lands in Phase 7 this needs a
decision: either grant HR `branches.view_all`, or have the staff endpoints not
apply `BranchScope`.

**`company_profiles` is not in backend spec §2.** The frontend introduces it
(types/organization.ts) and says so explicitly. Its public id is the literal
string `"company-profile"`, not the numeric key, because the frontend types it
as `z.literal("company-profile")`.

### Phase 2 notes and deferred items

**Password reset is email-based, but sign-in is by phone.** Laravel's password
broker is keyed on email, and `users.email` is nullable — so an account
provisioned without an email address has no self-service reset path and returns
`PASSWORD_RESET_UNAVAILABLE`. Phone/SMS reset would need an SMS provider and a
business decision about OTP delivery; neither is in the specification, so
nothing was invented. **The frontend has no forgot-password or change-password
screen**, so these three endpoints currently have no UI consumer — they were
built because Phase 2 asked for them explicitly.

**No `POST /auth/refresh`.** Frontend spec §2 step 6 bounds permission drift
with "a short session TTL plus a lightweight `refresh()`". `GET /auth/me`
already re-resolves permissions server-side and serves that purpose; a separate
token-refresh endpoint would need a decision on refresh-token semantics that
the specification does not make.

### Carried forward from the frontend

The frontend readiness report lists seven items that are specified but have no
UI. Each needs a decision on whether the API implements it ahead of the
frontend or waits: Groups module, loan top-up, bank-account CRUD,
category→product eligibility editing, `POST /staff`,
`POST /staff/performance`, and post-registration customer edits.

Also carried forward: **OSC-1**, the unresolved penalty-accrual posting
conflict — spec §7 asks for a `Dr Loan Arrears / Cr Expected Schedule` entry,
but "Expected Schedule" is not an account defined in §5, and §5 already
recognises penalty income on collection. The frontend posts nothing on accrual
to avoid double-counting. **This must be resolved before the Repayments module
is built.**
