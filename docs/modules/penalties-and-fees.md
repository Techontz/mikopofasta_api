# Module: Penalties & Loan Fees

Sidebar → **Penalty** (Penalty List, Paid Penalty) and **Loan Fee** (Deducted
Income).

Three screens, **no new tables**. Everything they show is written by an engine
that already existed, and this module is the reads over those records plus the
one thing that was configured-but-never-charged: the loan fee.

## Why there is no `penalties` table

A penalty already lives in three places that must agree: `penalty_due` on the
installment, `penalty_allocated` on the payment allocation, and `2200 Penalty
Income` in the ledger. A fourth copy would be a fourth thing to keep in step,
and the first time it disagreed with any of the others there would be no way to
say which was right.

So `ChargeLedgerQueries` reads through:

| Screen | Source | Written by |
|---|---|---|
| Penalty List | `loan_schedules.penalty_due` | `RunOverdueProcessAction` |
| Paid Penalty | `payment_allocations.penalty_allocated` | the repayment engine |
| Deducted Income | `loans.fee_charged` | `SettleDisbursementAction` |

These screens cannot drift from the loan book because they *are* the loan book,
filtered.

**Paid Penalty reads allocations, not `loan_schedules.penalty_paid`.** The
schedule column is a running total and cannot say when any of it arrived; the
allocation rows can, and the screen has a date column. Allocations are also what
the ledger posted against, so this list and `2200` count the same events.

## Loan fees, now actually charged

`docs/modules/loan-charges.md` listed four things wiring `loan_fees` into
disbursement would take. All four are done:

1. **Snapshot at application.** `ApplyForLoanAction` copies the product's fee
   into `fee_type_snapshot`, `fee_amount_snapshot` and
   `insurance_amount_snapshot`, for the same reason the interest and penalty
   rates are snapshotted: a borrower quoted 5% is owed 5%, whatever Settings
   says by the time the money moves.
2. **A fee leg on the disbursement entry** (below).
3. **`loans.fee_charged`** records what was actually withheld.
4. **Trial-balance tests** — they assert balance rather than fixed figures, so
   they needed no re-baselining. Verified: 763 pass and the seeded books balance.

All three snapshot columns are **nullable**, and that is deliberate. Null says
"no fee was agreed"; zero would say "a fee of nothing was agreed". Only the
first describes a loan on a product with no `loan_fees` row, and the Deducted
Income screen lists only real income.

### The posting

The borrower owes the full principal either way — **the fee is deducted from the
payout, not from the debt** — so Loan Receivable is still debited in full and
the credit splits:

| Debit | Credit |
|---|---|
| `1200` Loan Receivable — principal | `1100` Principal Account — principal − fee |
| | `2100` Fee Income — fee |

Principal Account is credited only with what actually left as capital, which
keeps its meaning (a running measure of capital deployed) true. Crediting it in
full and debiting the fee back would report capital that never left.

A product with no fee posts **two** lines, not three at zero: `LedgerService`
rejects a zero-amount line.

### Insurance

`loan_fees.insurance_amount` is added to what is withheld and credits `2100`
alongside the arrangement fee. §5 defines Fee Income as "Processing fees,
charges" and provides **no insurance account**, so this is the only account the
premium can honestly land in today.

The two amounts are stored separately on the loan, so the split survives. When
the Insurance module is scoped it can take its own account and only the credit
leg moves — the deduction logic does not change.

`LoanFeeSeeder` seeds insurance at **zero**, because no captured legacy row
shows a non-zero one and the six Deducted Income figures are explained in full
by a 5% fee alone (see below).

### Where 5% comes from

The legacy Deducted Income screen, by way of the frontend's
`MOCK_DEDUCTED_INCOME` fixture. All six captured rows are exactly 5% of the
approved loan, across two orders of magnitude:

```
  272,000 → 13,600      740,000 → 37,000      520,000 → 26,000
6,000,000 → 300,000     650,000 → 32,500    1,200,000 → 60,000
```

Transcribed, not invented — the same standard `LegacySource` holds.

## Penalty calculation

Unchanged. `PenaltyCalculator` already implemented §7 and §2.3 — grace period,
the three penalty types, and the cap — and the overdue job already tops up to
the computed figure rather than adding to it.

**One thing was deliberately left alone: OSC-4.** The penalty base is the
installment's outstanding *total*, which includes penalty already accrued, so a
repeated run compounds. The README flags this as **"needs a decision before
go-live"** — either the base excludes accrued penalty and the job becomes
genuinely idempotent, or compounding is intended and the cron must run once a
day — and pins the current behaviour with a named test so that changing it
breaks something visible.

That is a decision about what every borrower owes, and it is the owner's to
make, not this module's. Nothing here changed it.

## API

| Method | Route | Screen |
|---|---|---|
| `GET` | `/api/v1/penalties` | Penalty List |
| `GET` | `/api/v1/penalties/paid` | Paid Penalty |
| `GET` | `/api/v1/loan-fees/income` | Deducted Income |

Read-only. There is no endpoint to edit or delete a charge, and that is the
design: penalties are computed by the engine and fees fixed at application.
Editing one here would put the schedule at odds with what the engine would next
compute and with what the income account records.

All three take the same filters — `search`, `branch_id`, `customer_id`, `from`,
`to`, `sort` (`date` | `amount` | `customer`), `direction`, `page`, `per_page` —
and all three are paginated with the §1 envelope.

`from`/`to` filter the date each register is *about*: the installment's due date
for accrued penalties, the **payment's** `received_at` for collected ones (not
the allocation row's `created_at`, which for a payment allocated out of suspense
weeks later is not when the money arrived), and the disbursement date for fees.

`to` must not precede `from`. A reversed range would return nothing, which reads
as "no data" rather than "the filter is wrong".

### Totals

Every response carries totals over the **whole filtered set**, not the visible
page — a footer that only added up one page is a different number on page two.

- Penalty List: `totalCharged`, `totalPaid`, `totalOutstanding`
- Paid Penalty: `totalPaid` — equals `2200 Penalty Income`
- Deducted Income: `totalIncome` — equals `2100 Fee Income` — and `totalApproved`

Both equalities are asserted by tests, because they are the point: if a register
and its income account disagreed, one of them would be wrong about what the
company earned.

## Permissions

| Ability | Permission |
|---|---|
| Read any of the three registers | `loans.view` **OR** `repayments.view` |

Matches what the frontend already gates these screens on
(`config/legacy-nav.ts`). The pairing is not arbitrary: a penalty is a term of
the loan *and* money to be collected, so requiring both grants would shut each
role out of something it is responsible for.

Branch-scoped per §13, reaching through the owning loan — a schedule and an
allocation carry no branch of their own, so "this branch's penalties" means the
penalties on its book rather than the ones its staff happened to key in.

## Seeded data

`PenaltySeeder` back-dates three loans' first installments by 45, 30 and 12 days,
runs the **real** `RunOverdueProcessAction`, then collects one penalty through
the **real** `RecordCashPaymentAction`. Nothing writes `penalty_due` directly, so
every seeded figure is one `PenaltyCalculator` and the allocator actually
produced.

The back-dating is the only artificial part and is unavoidable: a database seeded
today has no loan that fell overdue in the past, and an empty penalty screen
demonstrates nothing.
