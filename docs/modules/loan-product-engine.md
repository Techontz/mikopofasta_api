# Loan Product Engine

The Loan Product is the single source of truth for pricing. Loans consume its
configuration; they contain no calculation logic of their own.

---

## 1. The pipeline

```
Loan Product  →  Calculation Strategy  →  Loan Engine  →  Repayment Schedule  →  Ledger
```

| Piece | File | Responsibility |
| --- | --- | --- |
| `LoanTerms` | `Engine/LoanTerms.php` | Frozen value object. Everything a loan needs to be priced, and nothing else. |
| `InterestStrategy` | `Engine/InterestStrategy.php` | The extension point. One formula, one class. |
| `InterestStrategyRegistry` | `Engine/InterestStrategyRegistry.php` | Resolves an administrator's `interest_formulas.code` to the class implementing it. |
| `LoanEngine` | `Engine/LoanEngine.php` | The only place a schedule is built. |
| `LoanEngineServiceProvider` | `Providers/` | The **only** file naming the concrete strategies. |

A caller hands the engine a loan and a start date, and receives a plan. It never
chooses a formula, never sees the arithmetic, and cannot reach it — which is
what stops calculation logic reappearing in a controller or a report later.

### What the engine deliberately does not do

Fees (`LoanFeeCalculator`), penalties (`PenaltyCalculator`), allocation
(`PaymentAllocator`) and postings (`LedgerService`) are separate. Folding any of
them into pricing would let a late payment or a fee change the interest that was
agreed.

---

## 2. Adding a formula

Three steps, none of which touch the engine:

1. Write a class implementing `InterestStrategy`.
2. Add it to `LoanEngineServiceProvider::STRATEGIES`.
3. Insert the `interest_formulas` row.

No controller, action, report, component or route changes. This is proved by a
test that registers an anonymous "BALLOON" strategy and prices a loan with it
purely through the interface.

An unimplemented code is **refused**, not defaulted. A registry that fell back
to SIMPLE would price the loan by an arithmetic nobody chose, and the schedule
would look entirely ordinary.

---

## 3. The formulas

| Code | Method | Interest |
| --- | --- | --- |
| `SIMPLE` | Equal principal | `P × r`, once over the tenure, spread evenly |
| `FLAT` | Equal principal | `P × r` **per period**, always on the original principal |
| `REDUCING` | Equal principal | `outstanding × r`, so it declines |
| `REDUCING_EMI` | Equal instalment (annuity) | `outstanding × r`, with `EMI = P·r(1+r)ⁿ / ((1+r)ⁿ−1)` |

### Why there are two reducing-balance formulas — client Decision 2

Both are internationally standard and both satisfy the client's stated rule
("outstanding principal decreases; interest is calculated from remaining
principal"). They differ in what is held constant:

- **`REDUCING`** holds the *principal portion* constant. Explicitly retained —
  the client asked for both, and removing it would reprice every product on it.
- **`REDUCING_EMI`** holds the *instalment* constant — the textbook amortisation
  table most people mean by the phrase. **The default for new products.**

The default is a column (`interest_formulas.is_default`, uniquely indexed), not
a constant. Changing the business's mind is a row update, and exactly one row
can hold it. The API serves `isDefault`, and the product form preselects it —
so the two sides cannot disagree about what the default is.

An **existing** product keeps its own formula, always. Editing a product's name
must never silently reprice it.

---

## 3b. What the rate means — P2, left open and made switchable

The client's instruction was explicit: *"DO NOT implement any assumption. Leave
this configurable... design the architecture so either option can be enabled
later without changing the loan engine."*

| Piece | File |
| --- | --- |
| `RateBasis` | `Engine/RateBasis.php` — the contract |
| `AsConfiguredBasis` | today's behaviour; the seeded default |
| `PerAnnumBasis` | APR, actual/365; seeded **inactive** |
| `RateBasisRegistry` | resolves `interest_rate_bases.code` → class |

A strategy no longer reads `$terms->interestRate`. It asks for
`periodicRate()` (FLAT, both reducing formulas) or `tenureRate()` (SIMPLE), and
the basis decides what the configured figure is worth over that span. Two spans
rather than one because the formulas genuinely need different things —
collapsing them is exactly the ambiguity that made P2 a question.

**The default converts nothing.** `AsConfiguredBasis` returns the rate untouched
for both spans, so introducing the mechanism changed no arithmetic: all 1,240
assertions in `LoanEngineFormulaTest` pass unedited. Continuity is not an
assumption — every loan in the book was priced this way, and the client's own
worked examples only come out right under it.

Enabling APR later is `UPDATE interest_rate_bases SET is_active = 1` plus
assigning it to a product. No strategy, engine or schedule code changes.

`Percentage::scaledBy()` exists for this: 24% × 1080 days is 25,920%, far beyond
a storable rate, so the multiply and divide happen on the underlying integer in
one step. Only the *result* has to be storable, which is the only thing that was
ever true.

---

## 4. No hardcoded values

| Was | Now |
| --- | --- |
| `interest_formulas.code` — `ENUM('SIMPLE','FLAT','REDUCING')` | `VARCHAR(40)`; the registry decides what can be priced |
| `loan_products.penalty_type` — `ENUM` | `penalty_types` master table, with `rate_unit` and `accrues_daily` as data |
| Repayment frequency | Already master data (`repayment_schedules`) |

`InterestFormulaCode` survives **only** as named constants for the three seeded
formulas. It no longer constrains what may be stored.

`penalty_types.rate_unit` is stored rather than inferred from the code, because
`PenaltyCalculator` must know how to read `loan_products.penalty_rate` for a type
it has never seen.

---

## 5. Product configuration

Added: `description`, `grace_period_days`, `processing_fee_rate`,
`insurance_fee_rate`, `commission_rate`, `recovery_commission_rate`,
`penalty_type_id`.

A **grace period moves the due dates and nothing else.** A product that forgave
interest during grace would be a different formula, and encoding that on the
product row would put calculation logic back where it must not live.

`commission_rate` is nullable — null means "use the company-wide rate", which is
every product's behaviour today. `recovery_commission_rate` is the higher rate
Decision Register D7 requires on money recovered after default.

---

## 6. Repayment allocation — client-confirmed, permanent

```
1. Penalty   →   2. Advance   →   3. Principal   →   4. Interest
```

**This closes P1.** The two source documents contradicted each other; the client
has settled it, and principal now comes before interest — which favours the
borrower, since every shilling clearing principal stops earning interest and
shrinks the base a percentage penalty is charged on.

One implementation, in `PaymentAllocator`. All intake channels use it.

### What "Advance" means at step 2

Advance is not a debt component — an installment has principal, interest and
penalty, and nothing else. Step 2 is therefore read as: **a credit the borrower
has already paid is spent before any new cash is.**

Penalties are paid from *cash*, never from an advance: spending money the
borrower paid early on a charge for being late is the opposite of what an
advance is for.

---

## 7. Advance payments — client Decision 1

```
Customer pays early
  → the money is HELD as a Customer Advance
  → the repayment schedule is left completely unchanged
  → when each installment reaches its due date, the advance is consumed
  → only the remaining amount, if any, is asked of the customer
```

An advance is a **prepaid credit**, not an early settlement.

`loan_advances` is a ledger of movements, not a balance column — the balance is
their sum, so it cannot drift from its own history. Positive credits, negative
consumes.

| Event | Posting | Source type |
| --- | --- | --- |
| Money received early | `Dr cash · Cr 5100 Customer Advance` | `repayment` |
| Installment falls due | `Dr 5100 Customer Advance · Cr income/receivable` | `advance_consumption` |

A surplus is **a liability, not income** — nothing has fallen due to earn it.
Recognising it would report profit the lender has not made. The consumption
moves no cash, which is why it is not filed as a repayment: doing so would make
the day's receipts read higher than the day's cash.

### The two halves

**`PaymentAllocator`** settles only installments whose due date has arrived —
one `break` on `due_date > $asOf`. Everything the due installments cannot absorb
falls out as `unallocated` for the caller to bank.

**`ApplyDueAdvancesAction`** is the other half: the daily pass that consumes held
credit the instant it becomes owed. Without it a borrower who paid three months
up front would still fall into arrears on schedule.

### Why the advance pass runs before the penalty job

`RunOverdueProcessAction` calls it first. An installment an advance can cover was
never late, and charging a penalty before spending the credit would put a charge
and its cancellation in the customer's statement for something that never
happened. Asserted as a test, not left to the order of two adjacent lines.

### What changed, and why it is deliberate

The allocator used to walk **every** unpaid installment. Early money silently
paid down the tail of the schedule — the borrower's plan changed underneath
them — and an advance balance could only form once the whole loan was covered,
at which point the loan closed and the consumption path became unreachable. That
limitation was reported; the client resolved it. Both are fixed by the same gate.

One consequence worth knowing: **paying the full balance early no longer closes
the loan.** The money is held and consumed installment by installment. Early
settlement is a separate, explicit workflow:
`GET`/`POST /loans/{loan}/early-settlement`.

### The settlement record

A settled loan carries the whole event, served. `earlySettledAt` and
`interestWaived` sit flat on the loan beside `closedAt` — null and `0.00` on a
loan that simply ran its course, which is what tells the two apart. The rest
arrives as one `earlySettlement` block, present only on the endpoints that load
it (`show`, and the response to the settlement itself):

| Field | Source |
| --- | --- |
| `settledAt` | `loans.early_settled_at` |
| `interestWaived` | `loans.interest_waived` |
| `amountPaid` | the linked payment; **null** when the waiver alone settled it |
| `reference` | the linked payment's `payment_reference` |
| `officerId` / `officerName` | `loans.early_settled_by` |

`amountPaid` is the one figure a browser could not have worked out: the waiver
reduces the balance **before** the money is taken, so subtracting outstanding
from payable gives what was owed before forgiveness, not what the customer
handed over. Serving it is what stops the screen and the record disagreeing.

The officer is stored separately from the payment rather than read off it,
because a loan whose entire remaining balance was unearned interest is settled
by the waiver alone and has no payment — but it always had somebody decide.

---

## 8. Verification

| Suite | Tests | What it proves |
| --- | --- | --- |
| `LoanEngineFormulaTest` | 29 | Arithmetic against hand-computed figures |
| `InterestRateBasisTest` | 11 | P2 switchable; the default converts nothing |
| `PaymentAllocationOrderTest` | 17 | The confirmed order, every listed scenario |
| `PaymentAllocatorTest` | 10 | Allocation mechanics |
| `CustomerAdvanceTest` | 15 | Hold, consume at due date, never penalise a funded installment |
| `LoanEngineAccountingTest` | 12 | Ledger reconciliation end to end |
| `LoanEnginePerformanceTest` | 8 | Scale and N+1 |

Expected values are written as **literals**. A test that recomputed the expected
figure with the code under test would pass whatever that code did.
