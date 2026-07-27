# Mikopofasta API

Backend for the **Mikopofasta Enterprise Microfinance Operating System** — a
Laravel 12, API-only service consumed by the Next.js frontend in
`../mikopofasta_web`.

The frontend is complete and is the API contract. Every endpoint, permission
string, status enum and ledger posting implemented here must match the two
approved specifications:

- `../mikopofasta_web/docs/backend-architecture-specification.md`
- `../mikopofasta_web/docs/frontend-technical-specification.md`

> **Status: Phase 1 — project initialization only.**
> No business modules are implemented yet. See [Roadmap](#roadmap).

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
```

Then edit `.env` — at minimum `DB_USERNAME`, `DB_PASSWORD`, and
`CORS_ALLOWED_ORIGINS` if the frontend is not on `http://localhost:3000`.

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

## Roadmap

Phase 1 is initialization only. Deliberately **not** yet built:

| Item | Notes |
|---|---|
| Error envelope | Spec §1 defines `{ message, error_code, errors }`. `withExceptions()` in `bootstrap/app.php` is the single place to implement it. |
| Idempotency middleware | Spec §1: `Idempotency-Key` on every money-moving endpoint, 24h replay window. The header is already allow-listed in CORS. |
| Domain schema | ~54 tables (spec §2). Only Laravel's own tables plus Sanctum and Spatie Permission exist so far. |
| RBAC seed | 11 roles and their permission strings (spec §14). Spatie is installed and its middleware aliased; nothing is seeded. |
| Chart of accounts | 19 system accounts (spec §5), seeded with `is_system=true`. |
| Business modules | Customers, Loans, Repayments, Ledger, Treasury, HR, Reports. |

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
