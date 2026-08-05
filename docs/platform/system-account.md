# The System Account — a permanent platform rule

Every automated process acts as one dedicated, non-login account. Never a Super
Admin, never an Admin, never the currently authenticated user, never null.

**If it does not exist, the platform fails fast.**

```
System account has not been initialized. Run database seeders.
```

---

## 1. Why a fallback is worse than a failure

The first version of `SystemActor` fell back to "the lowest-id Super Admin, or
failing that any user". That fallback does not fail — it *succeeds*, quietly,
and produces ledger entries attributed to a real employee who did not make them.

By the time anybody notices, the damage cannot be undone: there is no record
distinguishing the automation's postings from that person's. A missing account,
by contrast, is loud, immediate, names its own fix, and writes nothing before it
is resolved. That is strictly the better failure, and it is why the refusal is
now permanent.

`ConfigurationException` is deliberately **503**, not 500. The service is not
broken; it is not ready. That distinction is what a load balancer, an on-call
engineer and a half-finished deploy each need.

---

## 2. The guarantees, and where each one lives

| Guarantee | Enforced by |
| --- | --- |
| Exactly one exists | Unique index on a generated column (`users.system_account`) |
| Created on install | `DatabaseSeeder` → `SystemUserSeeder`; `migrate:fresh --seed` |
| Created safely on existing databases | `php artisan system:ensure-user` (idempotent) |
| Never duplicated | The index, plus `SystemActor` refusing when it finds more than one |
| Login disabled | `UserStatus::System::canAuthenticate() === false`, checked by `LoginAction` |
| Password login impossible | A fresh 64-byte random password, never recorded |
| Password reset impossible | No email (the broker is email-keyed) **and** `canAuthenticate()` is false |
| No interactive permissions | Role holds none; `RoleName::isEditable()` false; not in `assignable()` |
| Hidden from user management | `User::scopeHumans()` on the index; 404 on show/update/status/delete |
| Cannot be created or promoted | `system` absent from `RoleName::assignable()` and `UserStatus::assignable()` |
| Visible in the audit trail | Named as the actor on every automated posting and audit row |

### Why the uniqueness constraint is shaped that way

MySQL has no partial indexes. The standard equivalent is a `STORED` generated
column that is `1` for a system account and `NULL` otherwise, with a unique
index over it: a unique index permits any number of NULLs and exactly one `1`.

Generated rather than a real column so it cannot drift from `status` — there is
nothing to keep in sync and no way to set it wrongly.

A database cannot require a row to *exist*. That half is the seeder's on a fresh
install and `SystemActor`'s on every automated action. Between them the
invariant holds from both directions.

### Why hiding it returns 404 and not 403

The account is excluded from the user list. An endpoint that then refused it by
name with a 403 would confirm its existence and its id to anybody probing. From
user administration's point of view it is not there.

### The hole that was closed on the way

`UpdateUserStatusRequest` accepted every `UserStatus`, including `system`. On an
uninitialised database an administrator could have promoted a real person into
the automation's identity — the unique index only stops the *second* one.
`UserStatus::assignable()` now excludes it, and a test asserts the refusal.

---

## 3. Every automated process, and what it acts as

| Process | Entry point | Actor |
| --- | --- | --- |
| Penalty / overdue run | `penalty:apply` → `RunOverdueProcessAction` | System |
| Advance consumption | `ApplyDueAdvancesAction` (inside the above) | System |
| Provider payment webhook | `PaymentController::webhook` | System |
| Disbursement callback | `DisbursementCallbackController::webhook` | The batch's requester, else System |
| Automatic accounting entries | `LedgerService::post` from any of the above | System |

The disbursement callback is the one place a human is still named, and
correctly: the officer who requested the batch genuinely initiated that money
movement, so `created_by` traces to a real decision. When the batch has no
requester, it is System — previously it was "whichever user has the lowest id".

`RunOverdueProcessAction` and `ApplyDueAdvancesAction` resolve the account
**once, at the top of the run**, and use it for the ledger entry, the movement
rows, the status history and the audit log. Previously only the ledger posting
named it and the rest recorded null — the same action attributed three different
ways in three tables.

`penalty_runs.triggered_by` still records `cron`. The account says **who**, the
enum says **how**, and neither substitutes for the other.

---

## 4. Adding a new automated process

1. Inject `SystemActor`.
2. Call `resolve()` once, at the start.
3. Never pass null, never fall back, never catch the ConfigurationException.

There is no approved second way to obtain an actor for automated work.

---

## 5. Operations

```bash
php artisan system:ensure-user     # idempotent; safe on a live installation
curl /api/v1/health                # 503 + systemAccount: "missing" when absent
```

The health endpoint reports **readiness**, not just liveness: an installation
missing the account answers ordinary requests perfectly well and fails every
automated posting, so a check that said "ok" would be routing traffic to an
instance that cannot do half its job.

---

## 6. Tests

Seeded from a global `beforeEach` on every feature test, so the test
infrastructure mirrors production: permissions, roles and the System account —
the same three seeders `migrate:fresh --seed` runs first, in the same order. No
test creates the account by hand.

It is global rather than per-test because a test that forgot it would not fail
on the missing account; it would fail somewhere else entirely, on a 503 from
whichever automated path it happened to touch. `FoundationTest`'s health check
did exactly that, which is what prompted moving it.

`seedRbac()` short-circuits once the platform floor is down (it checks for the
System *role*, which no factory or fixture ever creates), so the explicit calls
throughout the suite cost one query each. They are left in place deliberately: a
test that spells out its own preconditions still reads correctly on its own.

`tests/Feature/Organization/EnterpriseStructureTest.php` covers all of it — the
four login barriers, the database refusing a duplicate, the 404s, the
non-assignable role and status, the ConfigurationException message, and the
health endpoint flipping to 503.
