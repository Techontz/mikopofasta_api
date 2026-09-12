# Final deployment audit

Baseline: commit **`d531271`**. Package: 52 code files, 5 migrations.

---

## A. Package test result

Every suite that exercises a file in this package:

```
tests/Feature/Customers  tests/Feature/Loans  tests/Feature/Organization
tests/Feature/MasterData tests/Feature/Audit

Tests:  637 passed (3455 assertions)     0 failed
```

**Zero failures in anything this package touches.**

Static analysis, on the whole application including every package file:

| Check | Result |
|---|---|
| PHPStan level 6 | 0 errors |
| Pint | pass |
| `php -l`, all 52 files | pass |

---

## B. The 3 excluded failures

| # | File | Test | Reason |
|---|---|---|---|
| 1 | `tests/Feature/Accounting/PeriodCloseTest.php` | *it refuses to close out of order while an earlier period is still open* | Expected `PeriodException`, none thrown |
| 2 | `tests/Feature/Accounting/PeriodCloseTest.php` | *it lists closed periods newest first* | `PeriodException: Period 2026-07 is already closed` |
| 3 | `tests/Feature/Hr/CommissionTest.php` | *pool generation → it carries an unrecovered loss into the next period* | Expected `'294000.00'`, got `'0.00'` |

### Cause: month-end arithmetic in committed test helpers

Today is the **31st**. The app timezone is `Africa/Dar_es_Salaam`, so the
application's `now()` is 2026-08-31. Carbon's month arithmetic overflows a
short month:

```
2026-08-30   pastPeriod(1)=2026-07   pastPeriod(2)=2026-06
2026-08-31   pastPeriod(1)=2026-07   pastPeriod(2)=2026-07   <== COLLIDE
2026-08-31   now()->addMonth() = 2026-10                     <== SKIPS SEPTEMBER
```

"August 31 minus two months" is June 31, which does not exist and rolls forward
to July 1. `pastPeriod(1)` and `pastPeriod(2)` therefore return the same period:
test 1 posts both trades into one period, the close legitimately succeeds
because no earlier period is open, and the expected exception never fires.
Test 2 then finds that period already closed. Test 3's `addMonth()` skips
September, so the carry-forward has no adjacent period to find.

These pass on the 1st–30th of any month and fail on the 31st.

---

## C. Proof they are unrelated to this package

Three independent experiments, each run against the real suite.

**1 — The failing test files are not in the package.**
`tests/Feature/Accounting/PeriodCloseTest.php` and
`tests/Feature/Hr/CommissionTest.php` are absent from
`mikopofasta-backend-update/`.

**2 — Reverting the excluded Profit Distribution code does not fix them.**
`ClosePeriodAction.php`, `AccountingPeriod.php` and `PeriodCloseTest.php` were
stashed back to `d531271` and the tests re-run:

```
3 failed, 26 passed        (unchanged)
```

**3 — Reverting the only package files those tests touch does not fix them.**
Of the 52 package files, exactly one appears in those tests' dependency list:
`app/Models/Branch.php`. With it and `BranchResource.php` also reverted to
`d531271` — no package file involved at all:

```
3 failed, 26 passed        (unchanged)
```

**Conclusion: the three failures exist at commit `d531271`, the production
baseline.** They are latent calendar bugs in committed test helpers. They are
not caused by this package and they are not caused by the excluded Profit
Distribution work. Deploying this package neither introduces nor worsens them.

### None of the three involves any of the following

Customer registration · Customer Types · Dynamic registration fields ·
Customer Type form configuration · Documents · ID Types · ID Type → Document
Type mapping · KYC · Branches (beyond an unused `customers()` relation, ruled
out by experiment 3) · Loan product branch assignment · Customer categories ·
The registration API · Any migration in this package.

Verified mechanically — the classes those two test files reference:

```
ClosePeriodAction, PeriodStatus, PeriodException, PeriodResultCalculator,
EmploymentStatus, BranchProfitCalculator, CommissionCalculator, JournalLine,
JournalSourceType, SystemAccountCode, AccountResolver, LedgerService,
TrialBalanceBuilder, AuditAction, AccountingPeriod, AuditLog, Branch,
CommissionDistribution, CommissionPool
```

Only `Branch` is in the package, and experiment 3 removes it as a suspect.

---

## D. Migration verification

Run against a scratch database (`mikopofasta_pkgaudit`, created and dropped
for the test).

**Apply to a clean database** — all 5 ran:

```
2026_09_01_000001_add_activation_to_customer_categories ...... DONE
2026_09_02_000001_add_reference_fields_to_loan_products ...... DONE
2026_09_02_000002_create_loan_product_branches_table ......... DONE
2026_09_03_000001_add_registration_form_configuration ........ DONE
2026_09_04_000001_link_id_types_to_document_types ............ DONE
```

**Columns created** — verified against `information_schema`:
`customer_categories.is_active`, `.form_title`, `.optional_documents`;
`id_types.document_type_id`; `loan_products.approval_stage_id`; and the
`loan_product_branches` table.

**No institution data inserted** — every table zero after migrating:

```
customer_categories 0   id_types 0   document_types 0   sectors 0
employers 0   contract_types 0   loan_product_branches 0   customers 0
```

`grep -rniE "insert|updateOrCreate|::create\(" database/migrations/` returns
only `Schema::create`. The migrations add structure and no rows.

**Rollback works and preserves data.** Rows were planted first (a customer
category, a document type, an ID type linked to it), then:

```
php artisan migrate:rollback --step=5 --force      → all 5 reversed
after rollback:  categories=1  id_types=1  doc_types=1     (intact)
added columns remaining: 0        loan_product_branches: 0
```

**Re-applying is safe** — migrated up again, planted rows still intact.

---

## E. Route verification

Route names extracted from all three versions of `routes/api.php`:

| | count |
|---|---|
| `d531271` (production now) | 245 |
| development working tree | 251 |
| **this package** | **249** |

**Lost from the baseline: none.** All 245 preserved.

**Added (+4):** `master-data.all`, `loan-products.branches.index`,
`loan-products.branches.store`, `loan-products.branches.destroy`.

**Excluded (−2):** `distribution-setting.show`, `distribution-setting.update`.

**Boot test.** The packaged file was swapped in and the router loaded:

```
✔ booted — 296 routes registered
✔ master-data.all
✔ loan-products.branches.index / .store / .destroy
✔ no distribution-setting routes
```

**Dependency test.** All **54** classes imported by the packaged
`routes/api.php` exist in `d531271` or in this package. Nothing references
`DistributionSettingController`.

---

## F. Production-data safety

| Check | Result |
|---|---|
| Destructive SQL outside `down()` | none |
| `migrate:fresh` / `db:wipe` / `migrate:reset` anywhere | none — `deploy.sh` greps itself and refuses to run if present |
| Rows inserted or updated by migrations | none |
| `.env`, credentials, API keys, private keys | none |
| Frontend files | none |
| Production or test data | none |
| Existing columns dropped | none |
| Existing rows rewritten | none |

Every new column is nullable or defaulted. `customer_categories.is_active`
defaults **true**, so existing customer types stay offered.
`loan_product_branches` empty means every branch, so existing products stay
available everywhere.

One behavioural note, stated so it is not a surprise: **`customers.marital_status`
is derived from `marital_status_id` on the next save of a customer.** It never
clears an existing value, and an update that does not mention marital status
leaves both columns alone. Existing customers are untouched until edited.

---

## G. Excluded Profit Distribution files

Verified absent from the package (`grep` for each returns 0 files).

**Modified in development, NOT shipped:**
- `app/Domain/Accounting/Actions/ClosePeriodAction.php`
- `app/Models/AccountingPeriod.php`
- `app/Enums/AuditAction.php`
- `tests/Feature/Accounting/PeriodCloseTest.php`

**New in development, NOT shipped:**
- `app/Http/Controllers/Accounting/DistributionSettingController.php`
- `app/Http/Requests/Accounting/UpdateDistributionSettingRequest.php`
- `app/Models/DistributionSetting.php`
- `database/migrations/2026_08_27_000001_create_distribution_settings_table.php`
- `tests/Feature/Accounting/ProfitDistributionTest.php`

**Shared file, stripped:** `routes/api.php` — the controller import and the two
`distribution-setting` routes removed.

> Do not overwrite this `routes/api.php` with the development copy. The
> development copy references a controller production does not have.

Also removed from the package during this audit:
`2026_08_30_000004_extend_customer_categories_for_registration.php`. It is
comment-only (no code line changed) and already applied in production. Laravel's
`migrations` table holds `id`, `migration`, `batch` — **no checksum column** —
so a modified applied migration is inert. It is not required and is now gone.

---

## H. Deployment commands

```bash
# 1. Backup — not optional
mysqldump -u <user> -p <database> > ~/mikopofasta-backup-$(date +%F-%H%M).sql

# 2. Record counts to compare afterwards
mysql -u <user> -p -e "SELECT (SELECT COUNT(*) FROM customers) c, \
  (SELECT COUNT(*) FROM loans) l, (SELECT COUNT(*) FROM customer_documents) d;" <database>

# 3. Upload
scp -r mikopofasta-backend-update <user>@<server>:/tmp/

# 4. Copy in, from the Laravel project root
cd /path/to/app
cp -r /tmp/mikopofasta-backend-update/app      ./
cp -r /tmp/mikopofasta-backend-update/database ./
cp -r /tmp/mikopofasta-backend-update/routes   ./
cp -r /tmp/mikopofasta-backend-update/tests    ./     # optional

# 5. Apply
bash /tmp/mikopofasta-backend-update/deploy.sh
```

`deploy.sh` runs: autoloader → `migrate --force` → `optimize:clear` →
`config:cache` + `route:cache` → `queue:restart` → route verification. It
demands a backup confirmation, refuses if `routes/api.php` still references the
excluded controller, and stops on the first error.

Manual, after: work through **`DATA-CHANGES.md`**. Nothing is seeded, and two
features stay dormant until configured.

---

## I. Rollback

```bash
php artisan migrate:rollback --step=5 --force     # verified: reverses cleanly
cd /path/to/app && git checkout -- app routes database tests
php artisan optimize:clear && composer dump-autoload --optimize
```

Full restore:

```bash
mysql -u <user> -p <database> < ~/mikopofasta-backup-<stamp>.sql
```

MySQL has no transactional DDL, so a migration that fails part-way may leave its
table half-changed — restore from the dump rather than relying on rollback in
that case.

---

## J. Remaining risk

| Risk | Severity | Mitigation |
|---|---|---|
| Linking an ID Type to a Document Type makes that document **required** at registration | Medium | It is off until you link. Link one, test the registration screen, then continue. `DATA-CHANGES.md` §B.2 |
| `routes/api.php` is a whole-file replacement | Medium | Verified: 245/245 baseline routes preserved, +4, −2. Boot-tested. If you have hand-edited routes on production since `d531271`, diff before copying |
| Marital status derivation on next customer save | Low | Never clears a value; presence-keyed |
| Read rate limit raised to 600/min | Low | Writes unchanged at 120/min; auth, password-reset and webhook limits untouched |
| Three suite failures on the 31st of the month | None to this package | Pre-existing at `d531271`, proven by three experiments. Fix is `->startOfMonth()->subMonths(n)` in two test helpers, in the excluded changeset |
| No staging rehearsal performed | Medium | Rehearse on a copy of production if you have one. All verification here was on a development machine |

---

## Verdict

**DEPLOYMENT READY: YES**

Zero failures in every suite this package touches (637 passed). Migrations
verified apply, roll back and re-apply on a clean database with planted rows
surviving. Routes verified complete, additive and boot-tested. No production
data is written, deleted or overwritten. The three suite failures are proven —
by three independent experiments — to exist at the production baseline itself,
untouched by this package.
