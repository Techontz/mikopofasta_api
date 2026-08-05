# Accounting — Month-End Close, Reserve, Reconciliation and Bad Debt

Decision Register **D1** and **D12**, plus §5's Write-Off and Recovered Loans
accounts. Shipped in Phase 1.

---

## 1. What changed, and why

The client ruled that the Reserve fund is **not** deducted from every
repayment. It is calculated from **realised profit during the accounting close**,
its use requires **Admin approval**, every movement is **fully audited**,
branches **cannot use it**, and it belongs to **Headquarters**.

That single ruling is the reason this module exists. Reserve had been taken as
`Dr Interest Income · Cr Reserve` on every interest collection, at a hardcoded
10%. Moving it to the close meant there had to *be* a close — a real business
event with its own record, rather than a report anybody could run twice.

Three consequences worth knowing:

| Before | After |
| --- | --- |
| Interest Income was net of reserve from the instant of collection | Interest Income reads **gross**; reserve appears at close |
| Branch profit was net of reserve, so §8 could say "(Reserve already netted out)" | Branch profit is **gross** of reserve, and `CommissionCalculator` deducts it explicitly |
| No accounting period existed | `accounting_periods` is the record of what was recognised, and when |

Historical entries carrying the old cut are untouched. This ledger is
reversal-only and history is not rewritten.

---

## 2. Month-end close

`POST /accounting/periods/close` · `accounting.period_close` (Finance)

Two postings, both dated the last day of the period:

```
1. Recognise profit          Dr each Income account      Cr Profit
                             Dr Profit                   Cr each Expense account

2. Appropriate reserve       Dr Profit  (branch-tagged)  Cr Reserve (company-wide)
```

The reserve credit deliberately carries **no branch dimension** while the debit
does. That is D1 in double entry: the profit came from a branch, and the reserve
does not belong to it. A branch reading its own ledger sees its profit reduced
and no reserve it could spend.

**Three preconditions**, checked before anything is computed:

- the period is not already closed — closing twice would double both postings;
- the period has ended — closing mid-month recognises a fortnight as a month;
- the prior period is closed, **unless it never traded** — a business that
  started in March cannot be asked to close February.

There is **no reopen**. D1 puts the appropriation inside the close, and
reopening would mean un-appropriating reserve Admin may already have released. A
mistake is corrected with a reversal in a later period. The UI offers a
`GET /accounting/periods/{period}/preview` first, which reads through the same
calculator the close uses, so the preview cannot disagree with the result.

### Why closing entries are excluded from P&L reads

The close sweeps income and expense into Profit **by posting to those very
accounts**. Anything that counted those postings would report every closed
period as having earned exactly nothing — and every commission pool derived from
it would collapse to zero.

`JournalSourceType::periodClosing()` names them once. Excluded by
`PeriodResultCalculator`, `BranchProfitCalculator` and
`ReportSources::periodIncomeExpense()`. **Not** excluded by the trial balance or
the balance sheet, which are positions rather than periods and must reflect the
movement of equity.

> **Incidental fix.** Six P&L-shaped reports read the *cumulative* trial
> balance, so `from` and `period` were accepted and silently ignored — "Branch
> P&L for August" actually showed inception-to-August. They now use
> `periodIncomeExpense()` and are genuinely period-scoped.

---

## 3. Reserve fund

| Endpoint | Grant | Held by |
| --- | --- | --- |
| `GET /reserve/utilisations` | `ledger.view` | Finance, Admin, Auditor |
| `POST /reserve/utilisations` | `reserve.request` | **Finance** |
| `POST /reserve/utilisations/{id}/approve` | `reserve.approve` | **Admin** |
| `POST /reserve/utilisations/{id}/reject` | `reserve.approve` | **Admin** |

The two grants are held by **different roles on purpose**. A single
`reserve.manage` would make D1's approval step decorative. `AccountingPolicy`
additionally refuses a decision by whoever raised the request (§14).

### Every purpose posts the same way

```
Dr  3000 Reserve
  Cr  1000 Capital
```

This is the client's own model — *"inaweza kurudi kwa njia ya mtaji"*, it
returns **by way of capital**. The Reserve is a **control account holding no
cash**: the close posts `Dr Profit · Cr Reserve`, which reserves a portion of
equity rather than segregating money. Releasing it therefore cannot move cash,
because there is none in it to move. It un-reserves equity, and the branch or
department is then funded from capital and spends through the ordinary expense
path.

> An earlier draft let the requester name a destination account and credited it
> directly. That was wrong twice over: crediting a bank account **decreases** it
> (assets are debit-normal), so the release drained the very account it claimed
> to fund; and it implied the reserve held cash it never held.

The purpose and target branch are recorded for the audit trail and the
utilisation report, not for the posting.

**The balance guard runs at approval, not at request.** Two proposals can both
be raised against a balance that only covers one, and the arithmetic that
matters is the arithmetic on the day of release.

---

## 4. Bank reconciliation

§15.3's `POST /finance/bank-reconciliation`, and **the gap that made `confirmed`
unreachable**. Until this shipped, nothing in the system could move a cash
payment out of `pending_verification`, which is what forced OSC-7's
ledger-anchored definition of "collected".

Two roles, two steps:

| Step | Endpoint | Grant | Posts |
| --- | --- | --- | --- |
| Teller banks the cash and names the payments it covers | `POST /cash-deposits` | `repayments.cash_entry` | **nothing** |
| Finance verifies the declaration and confirms receipt | `POST /cash-deposits/{id}/reconcile` | `repayments.reconcile` | `Dr Bank · Cr Teller Cash` |

The first step posts nothing deliberately. The cash was ledgered into the till
at the counter; carrying it to the bank does not move it between accounts until
the bank confirms receipt. Posting there would record money as banked on a
teller's say-so — exactly the fraud §7's two trust states exist to prevent.

**Nothing is recognised as income at reconciliation.** That happened when the
teller took the money. Treating this as the point of recognition would delay
every branch's revenue by however long its deposits take to clear.

Three checks before anything posts: the named payments must exist, must still be
awaiting verification, and must **sum exactly** to the amount banked. §7's
"amount mismatch → investigation" is refused rather than reconciled
optimistically — a reconciliation that tolerates a difference is not one.

`GET /cash-deposits/unbanked` is what the deposit form offers, so a teller never
types a payment id. Typing is how mismatches start.

---

## 5. Write-off and recovery

| Endpoint | Grant |
| --- | --- |
| `POST /loans/{loan}/write-off` | `loans.write_off` |
| `POST /loans/{loan}/recovery` | `loans.recover` |
| `GET /write-offs`, `GET /loans/{loan}/recoveries` | `loans.view` |

Both carry their own grant rather than riding on `loans.approve`: **the role
that originates a loan must not be the role that can forgive it.**

```
Write-off   Dr 4200 Write-Off Expense    Cr 1200 Loan Receivable
Recovery    Dr bank                      Cr 4300 Recovered Loans
```

### Only principal is written off

A defaulted borrower owes principal, accrued interest and accrued penalty. Only
principal reaches the ledger.

This system recognises interest and penalty on **collection**, not accrual —
the reading OSC-1 settled, and why the overdue job posts nothing. Interest that
was never collected was never recognised as income, so there is no revenue to
reverse; writing it off would debit an expense against earnings the books never
carried. The forgone amounts are still stored on the row, because the recovery
officer and the arrears report both need to know what the borrower actually
owed.

The schedules are **not** zeroed. A write-off is a decision about collectability,
not a renegotiation, and a recovery arriving later would have nothing to
reconcile against.

### A recovery is not a repayment

The receivable is gone, so crediting it again would drive the account negative
and allocate against schedules that no longer represent anything the books
carry. Recovered Loans is an income account rather than a reversal of the
write-off expense, which keeps both facts visible: the period that took the loss
shows the loss, the period that recovered shows the recovery.

This also matters beyond accounting — the client's rule that *"mikopo
iliyodefault ikirudishwa kutakuwa na commission kubwa zaidi"* needs recoveries
separable from ordinary collections **in the ledger**, not merely tagged in a
report.

A loan may be recovered in instalments, so many recoveries may point at one
write-off. The loan moves to `recovered` on the first; `WriteOff::outstanding()`
says how much is still being chased.

---

## 6. Commission — the change nobody asked for, prevented

Moving reserve to the close left branch profit **gross** of it. Left alone,
every commission pool in the system would have quietly grown by the reserve
share of interest — an economic change arriving as a side effect of a timing
decision, which is the kind nobody notices until payroll.

`CommissionCalculator::computePool()` therefore deducts it explicitly, **first**,
matching the client's own words — *"kwenye hiyo faida reserve inatolewa kwanza
maana ndo inalinda mtaji"*:

```
1. Reserve appropriation   (D1 — comes out first)
2. HQ 2% hold              (§11, on what survives the reserve)
3. Loss carry-forward      (§11)
4. Distributable
```

The figure used is the one the close **actually appropriated**
(`period_branch_results.reserve_appropriated`), not one recomputed — so a pool
always reconciles to the close it came from even if the reserve rate changes
later. Zero for a period nobody has closed, which is the honest answer.

---

## 7. Screens

All inside the existing **Bank** section. No new navigation structure; four
entries added to the sidebar group and the section rail it mirrors.

| Screen | Route | Gate |
| --- | --- | --- |
| Bank Reconciliation | `/treasury/reconciliation` | `repayments.view` |
| Accounting Periods | `/treasury/periods` | `ledger.view` |
| Reserve Fund | `/treasury/reserve` | `ledger.view` |
| Write-offs & Recovery | `/treasury/write-offs` | `loans.view` |

Gated more tightly than their neighbours and deliberately **not** on
`treasury.view` — these act on the books. Reconciliation is the exception: a
teller must reach it to bank the day's cash and holds no ledger grant.

Write-off and recovery are actions on the **loan detail page**, built from the
Loan module's own components, because both are decisions about one loan. The
Treasury register uses the Settings components for the same reason in the other
direction — a screen mixing two design systems reads as two screens.

The Bank overview gained three tiles: last period closed, reserve awaiting
approval, deposits to verify. All three fail soft — a Treasury reader may hold
`treasury.view` without `ledger.view`, and an overview that refused to render
because one tile could not be filled would be worse than a tile that says so.

---

## 8. Schema

| Table | Holds |
| --- | --- |
| `accounting_periods` | One row per `YYYY-MM`, created by the close. Stores the figures **and the reserve rate at the moment of close**, so a later rate change cannot reinterpret history. |
| `period_branch_results` | The per-branch breakdown §11 reads. After the sweep it could not be recovered from the income accounts. |
| `reserve_utilisations` | Request, decision, and the entry it produced. Null `journal_entry_id` while pending — reserve moves on approval. |
| `write_offs` | One per loan (UNIQUE). Principal separated from forgone interest and penalty. |
| `recoveries` | Many per write-off. `payment_id` nullable — a negotiated settlement does not always arrive through the payment rails. |
| `commission_pools.reserve_appropriation` | The deduction, stored in the order applied, so a pool can be explained rather than asserted. |

`cash_deposits` already existed and was unused; the reconciliation workflow is
what finally gave it a lifecycle.

---

## 9. Verification

- **1,019 backend tests**, 60 of them new across five suites
- Trial balance balanced after every workflow, live and in tests
- 22/22 browser end-to-end checks across Finance, Admin, Teller and Loan Officer
- `pint` clean · `tsc` clean · `eslint` 0 errors · `next build` passes

Live-verified guards: close-twice (409), close-out-of-order (409),
close-not-ended (409), reserve self-approval (403), reserve insufficient (409),
teller-reconcile (403), amount mismatch (409), reconcile-twice (409),
write-off-not-defaulted (409), write-off-twice (409), short reason (422),
manager write-off (403), recovery-without-write-off (409).
