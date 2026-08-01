# MikopoFasta ERP — Production Readiness Report

Final Production Phase, 1 August 2026.

Backend `mikopofasta_api` at `8629f93`, frontend `mikopofasta_web` at `a07f78f`.

---

## 1. Overall completion

**~95% of specified scope is built, tested and connected.**

That number is derived, not estimated:

| Dimension | Figure |
| --- | --- |
| Backend API routes | 229 |
| Backend tests | **944 passing**, 5,378 assertions |
| PHPStan | level 6, **0 errors** |
| Pint | clean |
| Migrations | 34, `migrate:fresh --seed` succeeds |
| Trial balance after seed | 241,786,689.09 debit = credit, **balanced** |
| Roles / permissions | 11 / 29 |
| Frontend routes | 109 — **97 live**, 4 data-free by design, 8 excluded |
| Frontend | tsc clean, eslint **0 errors** (3 warnings), build passes |
| Live render check | **109/109** return 200 or the correct 307, no error shells |

The missing ~5% is four specified workflows that were never built, listed in §2.
Nothing in the built scope is stubbed, mocked or partial: every page reads a
real endpoint, every endpoint reads real tables, and every financial report
reconciles to the ledger.

**Three modules are excluded by instruction and are not counted in the
denominator:** Agent, Insurance and VISA. No backend module was ever specified
for them; their eight screens still read `lib/legacy/source.ts`, which has no
other importer anywhere in the codebase.

---

## 2. Remaining known issues

### Missing workflows (specified, never built)

| # | Gap | Consequence |
| --- | --- | --- |
| 1 | **Bank reconciliation** — §15.3 `POST /finance/bank-reconciliation`. The `cash_deposits` table exists; the endpoint that moves a cash payment from `pending_verification` to `allocated` does not. | No payment ever reaches `confirmed`. This is the direct cause of OSC-7. |
| 2 | **Month-end close and profit posting** — §8's `Dr Income · Cr Profit`. | The commission engine derives branch profit from the ledger directly, so payroll is unaffected; the Profit Account itself is never posted. |
| 3 | **Write-off and recovery postings** — §5 defines `Dr Write-Off Expense · Cr Loan Receivable` and `Dr Cash · Cr Recovered Loans`. The arrears transitions that trigger them are unbuilt. | The Recovery report lists loan *states* rather than ledger balances. |
| 4 | **Dividends** — §2.8. Capital contributions are built; dividend declaration and payment are not. | Shareholders can be recorded and capital injected; nothing can be distributed. |

### Notification delivery

Module 6 built notification **template** management — trigger events, channels,
placeholders, one active template per event, eight Swahili SMS templates seeded.
Nothing sends a notification and nothing lists one. There is no
`GET /api/v1/notifications` and no dispatcher class.

The consequence is one screen: the dashboard notification bell reads
`lib/pending/notifications-fixture.ts`. It is the only fixture left in the
frontend, and it sits under `lib/pending/` rather than a mock directory to name
what it is — a screen waiting on a backend feature, not on wiring. The layout
calls it with `.catch(() => [])`, so the bell degrades to empty.

### Frontend lint warnings (3, none a defect)

React Compiler reports "Compilation Skipped: Use of incompatible library" in
`data-table.tsx`, `zone-form-dialog.tsx` and `registration-wizard.tsx`. All
three are TanStack Table or react-hook-form APIs (`watch()`) that cannot be
memoized safely. The components work correctly; they simply are not
auto-memoized. No action needed.

---

## 3. Open Specification Conflicts

**The register holds seven, OSC-1 through OSC-7. There is no OSC-8** — nothing
in either codebase, the specification or the docs defines one. A candidate
eighth is offered at the end of this section; it is labelled as a candidate
because it has not been through the same documentation as the other seven, and
inventing an entry to fill a numbering gap would be worse than saying so.

Each is documented in full in `README.md`. Four of the seven affect money.

| # | Conflict | Implemented reading | Decision needed | Money |
| --- | --- | --- | --- | --- |
| **OSC-1** | §7 asks the overdue job to post `Dr Loan Arrears / Cr Expected Schedule`. "Expected Schedule" is not an account §5 defines, and §5 already recognises penalty income on **collection** — posting on accrual would double-count every penalty. | The job posts **nothing**. Accrued penalty lives on `loan_schedules.penalty_due` and reaches the ledger once, on collection. The absence is stated in the endpoint's own response and in every audit row. | Ratify collection-basis recognition, **or** move to accrual — which needs a real contra account added to §5 *and* the collection posting changed to clear a receivable instead of recognising income. | ✅ |
| **OSC-2** | §2.3 types `loan_products.penalty_rate` as `DECIMAL(6,3)` (caps at 999.999) *and* says it may hold a flat amount. The Salary Advance product's flat fee is 10,000 TZS. | Widened to `DECIMAL(18,3)`, keeping the spec's single-column design. `penaltyRate()` and `penaltyFlatAmount()` expose both readings. | Ratify the widening, or split the column in the spec. | |
| **OSC-3** | §2.3 gives `interest_rate` no period. The spec names three formulas but never says per-period or per-annum. | Per **installment**, matching the frontend's documented formula. Visible only at short cadences: 400,000 at 8% over 90 *daily* installments totals 1,855,999 payable. | Either the rate is per-period (current — and daily products must be priced accordingly), **or** per-annum and the generator must pro-rate by `frequency_days / 365`. A pricing decision, not a code cleanup. | ✅ |
| **OSC-4** | §7 does not say whether a penalty may be charged on an unpaid penalty. | `PenaltyCalculator` takes its base from the installment's **outstanding total**, which includes accrued penalty — so a repeated same-day run computes a slightly larger figure and tops up. Pinned by a named test so changing it breaks a test rather than silently changing what borrowers owe. | Either the base excludes accrued penalty (and the job becomes genuinely idempotent), or compounding is intended and the cron must stay at once per day. | ✅ |
| **OSC-5** | §2.9 stores `loss_carry_forward` and §11 makes it decisive, but neither says how the figure is produced. | A period whose distributable profit went negative carries the shortfall forward, and carries nothing once cleared. The only reading under which the rule ever stops applying. | Ratify automatic carry-forward, or make it a figure Finance sets at month-end close — which would let a loss be written off deliberately rather than pursued indefinitely. | |
| **OSC-6** | §5 defers staff-fund postings to §11; §11 never gives them. | `Dr Staff Advance Receivable / Cr Staff Fund Account` — no cash or bank account is touched, so the books record an employee owing money the books never show leaving. Coherent only if the fund is **notional**. | Confirm the Staff Fund is a notional liability (current behaviour correct), or bank it and re-specify the disbursement posting. Recovery needs no change either way. | ✅ |
| **OSC-7** | The frontend's collections reports filter on `status === 'confirmed'`. This backend never reaches `confirmed` at all until bank reconciliation ships (§2, gap 1). | `ReportSources::collectedPayments()` anchors on the **ledger**: a payment that produced a journal entry, is matched to a loan, and is not reversed or duplicated. Reconciles to the cent and includes teller cash. | Ratify the ledger-anchored definition, or ship bank reconciliation and narrow to `confirmed` — in which case cash collections vanish from these reports until a deposit slip is matched. | |

### Candidate OSC-8 — which branch is head office when two sources disagree

Surfaced by the fix in `8629f93`. Two sources name the head office:
`company_profile.headquarters_branch_id` and the `branches.is_head_office` flag.
Until this phase, both `RecordCapitalAction` and `FloatAccountResolver` read a
property that does not exist, so the configured branch was **never consulted**
and the flag always won.

They now read the column, with the flag as fallback — matching the precedent
`ExpenseAccountResolver` already set, and tested on both sides. That makes the
company profile authoritative. **The business should confirm that precedence**,
because the alternative (the flag wins, and the profile field is display-only)
is equally defensible and the two have been silently disagreeing-in-favour-of-
the-flag for the entire life of the codebase.

---

## 4. External integrations still simulated

Four. Each is an isolated seam — replacing it is a single-class change, and
nothing else in the codebase knows how the value is obtained.

| Integration | Class | Current behaviour | To go live |
| --- | --- | --- | --- |
| **NIDA registry** (§9) | `App\Domain\Customers\Services\NidaRegistry` | Reproduces the frontend's simulator exactly, including its 32-bit hash, so a NIDA number resolves to the same person on both sides. Accepts a fixed OTP. | Replace with an HTTP client against the real registry. §9 forbids hand-typed identity data, so this is a **hard prerequisite for customer onboarding**. |
| **Bank e-mandate** (§15.2 `POST /bank/e-mandate`) | `App\Domain\Loans\Services\MandateGateway` | Accepts the fixed demo OTP `654321`. | Dispatch a real OTP through the bank and verify the reply. Blocks any product with `requires_mandate`. |
| **Vodacom KYC / telco verification** (§15.2 `POST /vodacom/kyc-verify`) | `RunTelcoVerificationAction` | The state transition and audit trail are real; the verification result is supplied rather than fetched. | Call the telco. A failure correctly rejects the loan outright — §10 gives `pending_credit_review` only two exits. |
| **Vodacom disbursement** (§15.2 + `POST /webhooks/vodacom/disbursement-status`) | `PrepareDisbursementAction` / `SettleDisbursementAction` | The batch, the ledger posting, the idempotency and the webhook are all real and signature-verified. The **outbound push** to the provider is not made. | Wire the outbound call. The inbound settlement path is production-ready today. |

**SMS/notification delivery is a fifth gap but not a simulation** — there is no
stub to replace, only templates with nothing to send them (§2).

---

## 5. Performance

### Fixed in this phase

| Issue | Before | After |
| --- | --- | --- |
| **Loan balances on list screens** | `GET /loans` emitted no balance, so the frontend made one `/loans/{id}/schedule` request **per disbursed loan** — capped at 60, degrading to a partial total past it. Rendering the app fast enough tripped the API's own 120/min rate limiter. | `Loan::scopeWithScheduleTotals()` sums the six DECIMAL columns in SQL. **Flat 5 queries per page whatever it holds**, asserted by a test comparing 1 row against 50. Measured through a counting proxy: **0 schedule requests**. |
| **Teller customer statement** | One `/payments?loan_id=` request per loan the customer held — a request count that grew with their borrowing history. | `GET /payments` takes `customer_id`, resolved through the loan. **2 requests**, concurrent, whatever the history. Measured: 1 payments request. |

Verified equivalence: SQL and PHP totals agree to the cent on all ten seeded
loans, including partly-paid ones. A loan with no schedule sums to NULL, which
the resource distinguishes from a row of zeros via `array_key_exists` — "owes
nothing" and "was never asked for" stay different answers.

### Recommendations before scale

1. **Whole-book reads on aggregate pages.** `/dashboard`, `/reports/*` summaries
   and several list screens call `getAllX()` helpers that page at 100 rows until
   exhausted. At the seeded volume this is 1–8 requests; at 50,000 customers it
   is ~500. The fix is server-side aggregate endpoints (counts and sums in
   `meta`) rather than client-side totalling. **This is the single largest
   scaling risk in the system** and was deliberately not built here — it needs
   new endpoints, which this phase excluded.
2. **Raise or scope the API rate limit.** 120/min per authenticated user is
   comfortable for a human but tight for a server-rendered page making 8
   concurrent calls. Consider a higher limit for the trusted frontend service
   account, or move aggregation server-side (which removes the need).
3. **Index review before load.** Add covering indexes on
   `loan_schedules(loan_id)`, `payments(loan_id, received_at)` and
   `journal_entry_lines(branch_id, created_at)` before the first large import,
   and re-run `EXPLAIN` on the report queries at production volume.
4. **Nothing is queued.** `QUEUE_CONNECTION=redis` is configured and the §1
   queues (`ledger`, `notifications`, `reports`) are named, but no class
   implements `ShouldQueue` — everything runs synchronously. That is *safe* for
   a ledger and correct today. Report exports and notification delivery are the
   two workloads that should move to a queue once they have volume.
5. **Redis is configured for cache, session and queue** but nothing is cached.
   The chart of accounts, permissions and branch hierarchy are read on nearly
   every request and change rarely.

---

## 6. Security

### Verified in place

- **Authentication** — Sanctum bearer tokens, `bcrypt` at 12 rounds,
  `Password::default()` on change and reset.
- **Authorization** — 11 roles, 29 permissions, policy-gated on every endpoint.
  §14 separation of duties enforced (self-approval blocked; HR approves,
  Finance disburses).
- **Branch scope (§13)** — `BranchScope` / `BranchScopeGuard` applied on every
  index and every pinned-record read. Violations write an audit row.
- **Webhook signatures** — `VerifyWebhookSignature` middleware, HMAC, runs
  before the payload is read. (Previously blocker B2, now closed.)
- **Idempotency** — `EnsureIdempotency` middleware on money-moving endpoints.
  (Previously blocker B3, now closed.)
- **Rate limiting** — 120/min authenticated, 30/min anonymous, 3 per 15 min on
  password reset per email, tighter still on login.
- **Audit logging** — every state transition records actor, reason and
  before/after. 90-day operations log retention configured.
- **Frontend session** — iron-session sealed cookie; every `lib/api/*` module is
  `server-only`, so no API token can reach the browser.
- **CORS** — origin allow-list, driven by `CORS_ALLOWED_ORIGINS` / `FRONTEND_URL`.

### Recommendations before go-live

1. **Sanctum tokens never expire** (`config/sanctum.php` → `'expiration' => null`).
   Set a finite lifetime and implement refresh. A leaked token is currently
   valid forever.
2. **`APP_DEBUG=true` in `.env.example`.** Ensure production sets `false` and
   `APP_ENV=production` — stack traces otherwise leak schema and file paths.
   Note that `Model::shouldBeStrict()` is *disabled* in production, which is
   deliberate but means missing-attribute bugs fail silently there and loudly
   everywhere else. The head-office bug fixed this phase is exactly that class.
3. **Rotate every secret before deployment.** `APP_KEY`, `SESSION_SECRET`, the
   webhook HMAC secrets and the DB password must all be freshly generated —
   never carried over from development.
4. **Force HTTPS and HSTS** at the edge. `SESSION_SECURE_COOKIE=true`,
   `SESSION_SAME_SITE=lax` on the frontend session cookie.
5. **Seeded credentials must not survive.** Every seeded user has the password
   `password`. Delete or force-reset all of them as part of the cutover.
6. **No 2FA** on any account, including Super Admin. For a system that moves
   money, consider TOTP at least for the Finance and Super Admin roles.
7. **Database at rest.** `DB_PASSWORD` is empty in the example config; ensure
   production uses a strong credential, TLS to MySQL, and encrypted backups.

---

## 7. Production deployment checklist

**Infrastructure**
- [ ] MySQL 8 provisioned, `utf8mb4`, TLS enabled, automated encrypted backups with a tested restore
- [ ] Redis provisioned for cache, session and queue (password-protected, not publicly reachable)
- [ ] PHP 8.4 with `opcache` enabled; `opcache.validate_timestamps=0`
- [ ] Node 20+ for the Next.js server
- [ ] TLS certificates for both API and web origins

**Configuration**
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` set
- [ ] `APP_KEY` generated fresh (`php artisan key:generate`)
- [ ] `SESSION_SECRET` generated fresh (frontend, ≥32 chars)
- [ ] `CORS_ALLOWED_ORIGINS` set to the real frontend origin only
- [ ] `LARAVEL_API_URL` on the frontend pointing at the real API
- [ ] Webhook HMAC secrets set for Vodacom and the bank
- [ ] `APP_TIMEZONE=Africa/Dar_es_Salaam` confirmed (all date logic depends on it)
- [ ] `LOG_LEVEL=info` or higher; `LOG_OPERATIONS_DAYS` retention agreed with compliance

**Release**
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] `php artisan config:cache route:cache view:cache event:cache`
- [ ] `php artisan migrate --force` (**not** `migrate:fresh`)
- [ ] Reference seeders only — chart of accounts, roles, permissions, notification templates. **Not** the demo data seeders
- [ ] `npm ci && npm run build` for the frontend
- [ ] `php artisan schedule:work` or a system cron running `schedule:run` every minute — **without it no penalty ever accrues**
- [ ] Queue worker if any workload has been moved to a queue (none today)

**Verification after deploy**
- [ ] `GET /api/v1/auth/me` returns 401 unauthenticated
- [ ] Login with a real account; confirm the dashboard renders
- [ ] `GET /api/v1/ledger/trial-balance` balances
- [ ] Webhook endpoints reject an unsigned request
- [ ] A test penalty run produces no ledger posting (OSC-1 behaviour) and writes audit rows

---

## 8. Go-live checklist

**Business decisions — must be answered before real money moves**
- [ ] **OSC-1** penalty recognition: collection basis (current) or accrual
- [ ] **OSC-3** interest rate period: per-installment (current) or per-annum — **this changes every price**
- [ ] **OSC-4** penalty base: excludes accrued penalty, or compounding intended
- [ ] **OSC-6** Staff Fund: notional liability (current) or banked
- [ ] **OSC-2** ratify the `DECIMAL(18,3)` widening
- [ ] **OSC-5** loss carry-forward: automatic (current) or Finance-set
- [ ] **OSC-7** "collected": ledger-anchored (current) or bank-reconciled
- [ ] **Candidate OSC-8** head office precedence: company profile (current) or the `is_head_office` flag

**Integrations**
- [ ] NIDA registry contracted and connected — **blocks customer onboarding**
- [ ] Bank e-mandate connected — blocks mandate-requiring products
- [ ] Vodacom KYC connected — blocks credit review
- [ ] Vodacom disbursement outbound call wired — inbound settlement already works
- [ ] SMS provider chosen and a dispatcher built for the seeded templates

**Functional gaps — decide ship-now or ship-later for each**
- [ ] Bank reconciliation endpoint (also resolves OSC-7)
- [ ] Month-end close and profit posting
- [ ] Write-off and recovery postings
- [ ] Dividends
- [ ] Notification list endpoint (unblocks the last frontend fixture)

**Data**
- [ ] Chart of accounts reviewed and signed off by Finance
- [ ] Opening balances loaded and the trial balance confirmed balanced
- [ ] Branches, zones, regions and the company profile configured — including which branch is head office (see candidate OSC-8)
- [ ] Loan products, interest formulas, repayment schedules and fee/penalty settings entered and checked against the price list
- [ ] Real staff and users created; **every seeded account removed**
- [ ] Customer and loan migration from the legacy system planned, dry-run, and reconciled

**Operational readiness**
- [ ] Roles mapped to real people; separation of duties confirmed with the branch managers
- [ ] Staff trained on the approval chains (§16.7 HR approves / §16.8 Finance disburses)
- [ ] Error monitoring and uptime alerting connected
- [ ] Backup restore rehearsed end to end
- [ ] Rollback plan agreed for the first week
- [ ] Support path defined for the three excluded modules (Agent, Insurance, VISA) — they are **not** functional

---

## 9. Verification evidence for this phase

| Gate | Result |
| --- | --- |
| Pint | clean |
| PHPStan level 6 | **0 errors** (was 15 — all fixed at source, no ignores, no baseline) |
| `migrate:fresh --seed` | succeeds, 34 migrations |
| Trial balance | 241,786,689.09 = 241,786,689.09, balanced |
| Backend suite | **944 passed**, 5,378 assertions, 703s |
| Frontend `tsc --noEmit` | clean |
| Frontend `eslint` | 0 errors, 3 warnings (React Compiler, non-defects) |
| Frontend `next build` | compiled, 103 pages generated |
| Live render, all routes | **109/109** — 200, or the correct 307 for `/` and `/login` |
| Fan-out measurement | 0 schedule requests on loan pages, 1 payments request on the teller session |
