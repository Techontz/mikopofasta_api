# Phase 1 — Production Readiness

**Status:** frozen at `v1.0-phase1-production`
**Verified:** 7 August 2026

This document records what Phase 1 delivers, what was verified and how, and
what a reader taking this into production needs to know. It is deliberately
specific about limitations: a readiness report that lists only what works is
not a readiness report.

---

## 1. Architecture

Two repositories, one contract between them.

```
mikopofasta_api   Laravel 12 · PHP 8.4 · MySQL · Sanctum
mikopofasta_web   Next.js 16.2 (App Router, Turbopack) · React 19 · Tailwind v4
```

**The browser never holds an API token.** The frontend authenticates through
iron-session: a sealed, HTTP-only cookie carries `{ user, token }`, and the
Sanctum bearer is attached server-side by `lib/api/*`. Every call the browser
makes is to Next, never to Laravel. This is why `proxy.ts` — not
`middleware.ts`, which Next 16 deprecates — can enforce RBAC before a route
renders.

The API is organised by domain, not by layer:

```
app/Domain/{Accounting, Auth, Customers, Expenses, HR, Ledger, Loans,
            Notifications, Organization, Repayments, Reports, Treasury}
```

Each holds its own Actions, Services, Enums, DTOs and Policies. Controllers
stay thin: authorise, delegate to an Action, return a Resource.

**Two invariants worth stating, because most of the code exists to hold them:**

- *Branch scope (§13).* Every read and write is narrowed to the caller's
  branch unless they hold `branches.view_all`. Reaching outside it returns
  `BRANCH_SCOPE_VIOLATION` **and is itself audited** — a snooping attempt is a
  recorded event, not a silent 403.
- *Audit in the same transaction.* `AuditLogger` is called inside the caller's
  transaction, never dispatched as a job. A committed change with a
  rolled-back audit row is worse than no audit trail, because it looks
  authoritative.

---

## 2. Completed modules

| Module | State |
|---|---|
| Identity, roles, permissions | Complete — 17 roles, 37 permissions, per-user grants |
| Organization | Complete — branches, zones, regions, HQ-as-branch, approval routing |
| Customers & KYC | Complete — registration, profile, documents, face KYC, status lifecycle |
| Face KYC (biometric) | Complete — liveness scan, scored report, immutable history, audit |
| Loans | Complete — products, engine, approval chain, disbursement, early settlement |
| Repayments | Complete — allocation, reversal, cash deposits, reconciliation |
| Ledger & accounting | Complete — double entry, period close, reversal workflow |
| Treasury | Complete — bank accounts, capital, float transfers, HQ transactions |
| HR & payroll | Complete — staff, payroll, advances, staff loans, commission |
| Expenses | Complete — categories, requests, approval, HQ expenses |
| Reports | Complete — 20 report screens off a shared report source |
| Self-service profile | Complete — profile, security, preferences, activity |

**Customer platform detail.** Registration reproduces the legacy three-step
wizard field-for-field. Every dropdown reads an admin-managed master-data
table — ten of them — never a TypeScript enum. Face KYC records a full
verification report (per-check results, five graded measurements, device,
resolution, duration, operator, IP) and **never overwrites a scan**: a re-scan
supersedes its predecessor and both survive.

---

## 3. Database

| | |
|---|---|
| Tables | **107** |
| Columns | **1,174** |
| Migrations | **59** |
| Seeders | **25** |

**Migration integrity — verified, not assumed:**

- `migrate:fresh --seed` from a genuinely empty database: **all 59 migrations
  and every seeder succeed.** No ordering failures, no missing dependencies.
- `migrate:rollback` then `migrate`: schema returns **byte-identical** —
  compared as 1,174 `table.column:type:nullable` rows, not a dump hash (which
  drifts on AUTO_INCREMENT and would give a false pass).
- Seed data is idempotent: a fresh install and the existing dev database hold
  identical counts across users, roles, permissions, branches, customers,
  products, formulas, penalty types and templates.
- `migrations` table holds 59 rows, 59 distinct — no double-applied migration.

**A rollback defect was found and fixed during this pass.**
`2026_08_18_000001_create_loan_product_engine_tables` widened
`interest_formulas.code` from `ENUM('SIMPLE','FLAT','REDUCING')` to VARCHAR so
formulas could be added without a deploy; `LoanProductSeeder` then adds
`REDUCING_EMI`. Its `down()` narrowed the column straight back, truncating that
row and aborting with SQLSTATE 1265. **Rollback was therefore impossible on any
seeded database** — that is, on every real installation — and it failed at
migration 8 of 59. The `down()` now clears codes the original enum cannot hold
before narrowing.

---

## 4. API

**271 registered routes**, all under `/api/v1`, all Sanctum-authenticated
except login, password reset, the health check, and the signed file routes.

Response envelope is fixed (`ApiResponse`):

```
success  { "data": …, "meta": { "pagination": { page, perPage, total } } }
error    { "message": …, "error_code": …, "errors": { … } }
```

Casing is inconsistent *by necessity* and the inconsistency is enforced in one
place: resource attributes are camelCase (the frontend validates with Zod and
has no mapping layer), the error envelope is snake_case, query parameters are
snake_case.

**Private file access.** KYC documents, customer photos, face scans and staff
portraits live on a private disk and are only ever reachable through signed,
time-limited URLs (5 minutes). A stored path never appears in a response.
These routes sit outside Sanctum deliberately: an `<img>` tag cannot carry a
bearer token, so the signature *is* the credential.

**Rate limit:** 120 requests/minute per authenticated user. Worth knowing
before automating against it — a single profile page issues around a dozen
calls, and bulk tooling will hit 429 quickly.

---

## 5. Tests

| Suite | Result |
|---|---|
| Backend (Pest) | **1,267 passed · 8,387 assertions** |
| Test files | 67 |
| Pint | passes |
| PHPStan | **0 errors** |
| TypeScript | clean |
| ESLint | 0 errors, 8 warnings |
| Next build | 111 pages |

**PHPStan went from 15 errors to 0 during this pass.** All 15 were real —
missing `@property` declarations on models whose columns had grown, a resource
proxying an abstract model that declared no properties, missing Builder
generics, and two `create()` calls whose array spread erased the key type.
Fixed at source; **no baseline entries and no `@phpstan-ignore` comments**, per
the project's own PHPStan instructions.

### Remaining lint warnings (8, all pre-existing)

| Count | Rule | Why it stands |
|---|---|---|
| 4 | `react-hooks/incompatible-library` | TanStack Table and React Hook Form return functions the React Compiler cannot memoize. Upstream, not ours. |
| 3 | `@typescript-eslint/no-unused-vars` | Deliberate discards (`_capture`, `_report`) and one stale import. |
| 1 | `@next/next/no-img-element` | A signed, expiring URL to a private disk. `next/image` would cache it, which is the opposite of what an expiring credential wants. |

**There is no frontend test framework.** `package.json` has `dev`, `build`,
`start`, `lint` and no runner. Frontend verification throughout Phase 1 was
Playwright driven ad hoc against a real browser and a real API — thorough, but
**not committed and not repeatable in CI.** This is the largest testing gap.

---

## 6. Deployment

A fresh installation needs no manual SQL and no hidden steps.

```bash
# API
cd mikopofasta_api
composer install --no-dev --optimize-autoloader
cp .env.example .env && php artisan key:generate
#   set DB_*, APP_URL, CORS_ALLOWED_ORIGINS, MAIL_*
php artisan migrate --seed --force
php artisan config:cache && php artisan route:cache
php artisan storage:link

# Web
cd ../mikopofasta_web
npm ci
#   set API_BASE_URL and SESSION_PASSWORD (32+ chars) in .env
npm run build && npm run start
```

**Before going live:**

1. **Change every seeded password.** All eleven demo accounts use `password`.
   They are development fixtures and must not survive.
2. Confirm the `kyc` disk is `visibility: private`, `serve: false`, and is
   **not** symlinked into `public/`.
3. Set `APP_DEBUG=false` and a real `MAIL_MAILER` (it ships as `log`).
4. Raise `memory_limit` — see Known limitations.

---

## 7. Rollback

**Take a dump first. Always.**

```bash
mysqldump -u USER -p --single-transaction --routines --triggers \
  DATABASE > backup-$(date +%Y%m%d-%H%M%S).sql
```

Then:

```bash
php artisan migrate:rollback          # reverses the last batch
php artisan migrate                   # re-applies
```

**Read this before running it.** All 59 migrations were applied in a single
batch, so `migrate:rollback` is a **complete teardown** — it drops every table.
That is verified to work and to restore an identical schema, but it destroys
all data. On an installation where migrations arrived in separate batches only
the most recent batch reverses.

To restore data:

```bash
mysql -u USER -p DATABASE < backup-YYYYMMDD-HHMMSS.sql
```

---

## 8. Known limitations

**Not built, and stated rather than implied in the UI:**

- **Two-factor authentication.** The Security tab says "Coming soon" and shows
  no toggle. A switch that did nothing would be worse than none.
- **Administration screens for master data.** All ten lookup lists are
  API-only — nothing in the UI creates or renames an entry. This contradicts
  the intent that they be "managed from the Administration module" and is the
  most visible functional gap.
- **Department and Position** are not stored anywhere for staff. The profile
  shows every field the system genuinely holds and marks absent ones "Not
  recorded" rather than rendering a blank that implies missing data.
- **Supervisor is derived, not stored.** The only reporting edge in the schema
  is `zones.zone_manager_id`, so users outside a zone have no recorded
  supervisor. Inventing a column would be inventing an org chart.
- **No staff notification channel.** The password-change notification goes by
  mail; with `MAIL_MAILER=log` it lands in the log. There is no in-app
  notification store for staff.

**Operational:**

- **`memory_limit=128M` cannot run the test suite.** It needs ~1 GB. Raising
  it via `php -d` does not help, because `artisan test` spawns a subprocess
  that re-reads `php.ini`. Run `./vendor/bin/pest` directly or raise the ini.
- **One duplicate index.** `staff_advances.reference` carries both
  `staff_advances_reference_index` and `staff_advances_reference_unique`; the
  unique index already serves lookups. Harmless — a small write and disk cost.
  Left in place deliberately: changing schema during a freeze is the wrong
  trade. Drop it in Phase 2.
- **Face scan capture is unverified end to end with a real face.** The scanner,
  its scoring, the report and the whole submission path are verified; what is
  not is a human completing the five-pose sequence on camera. That needs a real
  face video and was never available.
- **The 20-registration soak test was never run**, for the same reason.

---

## 9. Phase 2 starting point

In the order I would take them:

1. **Frontend test framework.** The single largest risk. Every UI regression
   this phase — clipped dropdowns, transparent panels, a broken timeline
   filter — was found by driving a browser by hand. None of it is repeatable.
2. **Administration UI for master data.** Ten lists, one shared controller and
   resource already behind `admin.org_settings`. The API is done; the screens
   are not.
3. **Two-factor authentication.** The Security tab has a place for it.
4. **Department, Position, and a real reporting hierarchy** on
   `staff_profiles`. The profile picks them up automatically once present.
5. **Drop the duplicate `staff_advances.reference` index.**
6. **A face-scan soak test** using a recorded video via
   `--use-file-for-fake-video-capture`, which unblocks the registration run.

---

*Verified on macOS 24.4.0, PHP 8.4, MySQL 8, Node 24.1. Backend suite 1,267
passing at the time of the freeze.*
