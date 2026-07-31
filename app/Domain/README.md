# Domain Layer — where code goes

This file exists so that Phase 2 placement decisions are never a judgement
call. It describes the layout only; it introduces no behaviour.

## The rule

**`app/Domain/*` holds behaviour. `app/Models` holds persistence.**

Eloquent models stay in `app/Models` (Laravel's convention) so that factories,
`Model::factory()` resolution, policy auto-discovery and Larastan's model
property analysis all work without custom resolvers. Everything that *does*
something with those models — actions, services, DTOs, enums, policies,
exceptions — lives under the domain that owns it.

## Layout

```
app/
├── Domain/
│   ├── Auth/            authentication, tokens, RBAC assignment
│   ├── Organization/    branches, zones, regions, bank accounts,
│   │                    expense categories, loan products, categories
│   ├── Customers/       registration, KYC, groups, risk scoring
│   ├── Loans/           eligibility, schedule generation, state machine,
│   │                    mandate, telco verification, disbursement
│   ├── Repayments/      intake channels, allocation, suspense, penalties
│   ├── Ledger/          chart of accounts, posting, reversal, balances
│   ├── Treasury/        capital contributions, dividends
│   ├── HR/              staff, payroll, commission, advances
│   └── Reports/         read-models over every module above
│       ├── Actions/     single-purpose, one public method (`handle`)
│       ├── DTOs/        immutable readonly input/output shapes
│       ├── Enums/       backed enums mirroring the DB ENUM columns
│       ├── Exceptions/  domain failures carrying a stable `error_code`
│       ├── Policies/    authorization for this domain's models
│       ├── Services/    stateful/coordinating logic (e.g. LedgerService)
│       └── Support/     pure lookup/reference data (added in Phase 2 for
│                        RolePermissionMatrix; only where a domain needs it)
│
├── Actions/     cross-domain actions used by more than one domain
├── DTOs/        shared shapes (pagination, filters, API envelopes)
├── Enums/       global enums not owned by a single domain
├── Exceptions/  base exception types and the error-code contract
├── Services/    shared infrastructure services
├── Support/     helpers, traits, value objects (Money, Period, …)
├── Policies/    policies for models not owned by one domain
├── Models/      ALL Eloquent models
├── Http/
│   ├── Controllers/  thin — validate, delegate to an Action, return a Resource
│   ├── Requests/     FormRequests; all validation lives here
│   ├── Resources/    API response shaping (the `data` envelope, spec §1)
│   └── Middleware/
└── Providers/
```

## Dependency direction

Dependencies point **inward and downward**, never sideways between sibling
domains at the same level:

```
Http  →  Domain/<Module>/Actions  →  Domain/<Module>/Services  →  Models
                                  ↘  Domain/Ledger/Services (the one shared sink)
```

`Domain/Ledger` is the single exception every other domain may depend on —
it is the system's only write path to `journal_entries` (backend spec §5, §8).
No domain may depend on `Domain/Reports`; reporting is strictly read-only and
depends on everything else.

## Namespacing

PSR-4 maps `App\` → `app/`, so the namespaces follow the directories with no
extra composer configuration:

```php
App\Domain\Loans\Actions\ApproveLoanApplication
App\Domain\Ledger\Services\LedgerService
App\Models\Loan
```

## Policies are registered explicitly

Because policies live under `app/Domain/<Module>/Policies` rather than
`app/Policies`, Laravel's convention-based discovery does not find them. Every
policy must be registered in
`AppServiceProvider::configurePolicies()` — a policy that exists but is not
registered fails open, which is the worst possible failure mode for
authorization code.
