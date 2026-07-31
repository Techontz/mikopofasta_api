# Module: HR & Payroll

Sidebar → **HRM**: Overview, Staff, Inactive Staff, Branch & Staff, Payroll,
Commission, Loans & Advances, Staff Loan, **Staff Fund**, Performance.
Bank → **Payroll** is the same data from the employee's side.

Source of truth: `🧠 OVERVIEW STAFF COMMISSION.docx`, plus spec §11, §12 and
§14. Section numbers below are the HR document's.

## What was already right

The ledger postings match §4 and §9 exactly, and none of them changed:

| Event | Entry |
|---|---|
| Recognition | Dr Salary Expense + Dr Commission Expense · Cr Staff Payable |
| Deductions | Dr Staff Payable · Cr Staff Fund / Staff Loan Rec. / Staff Advance Rec. |
| Payment | Dr Staff Payable · Cr Bank |
| Advance / loan out | Dr Staff Advance Rec. / Staff Loan Rec. · Cr Staff Fund |

So did the commission engine — branch profit, less loss carry-forward, less the
HQ 2% hold, times the pool percentage, distributed by salary ratio, with the
zone override on top — and §14's separation of duties on generation and
finalisation.

## The defect this module exists to fix

**A staff loan never finished, and its recovery was never capped.**

`StaffLoanStatus::Closed` was assigned nowhere in the codebase.
`PayrollCalculator` deducted a flat `RECOVERY_PER_PERIOD` of 50,000 from anyone
with an `active` loan — the same figure whatever they had borrowed, and with no
check against what was left.

Twelve payroll runs simulated against the seeded 500,000 loan:

```
start 7010 net: 450000.00   loan amount: 500000.00
  2027-05  7010 net =      0.00   status = active   <- fully repaid
  2027-06  7010 net = -50000.00   status = active
  2027-07  7010 net = -100000.00  status = active
  2027-08  7010 net = -150000.00  status = active
```

Money taken from an employee who had already repaid, and `7010 Staff Loan
Receivable` asserting the company owed them 150,000. **The trial balance stayed
balanced the whole way** — both sides of each deduction entry moved together —
which is exactly why nothing caught it.

This is the salary-advance defect of Module 5, in the sibling module that did
not get fixed at the time. The two now share a shape: `RecoverStaffLoanAction`
is `RecoverStaffAdvanceAction` against a different debt.

After the fix, the same twelve runs:

```
  2027-05  7010 net = 0.00   recovered = 500000.00   status = closed
  2027-06  7010 net = 0.00   recovered = 500000.00   status = closed
```

`staff_loans` gained `amount_recovered` and `recovery_periods`; the instalment
is the principal over the agreed term, capped at what is owed; the loan closes
on the instalment that clears it. `RECOVERY_PER_PERIOD` is gone.

### And no split, unlike an advance

An advance recovery divides between principal (→ 7020) and charges (→ 7000),
because an advance is priced with interest and a fee. §14 describes a staff loan
as principal only, so the whole instalment clears the receivable and 7010 walks
to zero exactly.

## Staff loans have a lifecycle now

Before this module only `StaffSeeder` could create one. §14 defines the flow and
§16.7–16.8 say who does which: **request → HR approves → Finance disburses →
payroll recovers**. The same route an advance takes, because the rule is the
same rule, and two routes for one rule are two places for it to drift.

One loan at a time per employee: two concurrent recoveries could take a salary
below zero.

## Allowances are rows, not constants

`PayrollCalculator` held `TRANSPORT_ALLOWANCE` and `AIRTIME_ALLOWANCE` as class
constants. Two consequences:

- every branch employee necessarily drew the identical transport figure;
- **`AllowanceType::Bonus` was unreachable.** It was in the enum, in the
  `allowances` table and on the frontend from the beginning, and nothing in the
  system could ever award one — which is the single allowance a manager actually
  needs to decide. §10 lists it beside transport and airtime.

`staff_allowances` is what an employee is *entitled to*; `allowances` stays what
a payslip *paid*. Keeping them apart is what lets a rate change next month
without rewriting last month's payslip.

A row with no `period` is recurring; one with a period applies to that month
alone. **A bonus is always forced to a period**, whatever the caller sends: a
bonus that repeated silently every month would be a salary increase nobody
approved, and a salary increase belongs on the profile where it is visible.

One live recurring allowance of each type per employee, enforced by a partial
unique index — the same generated-column technique `notification_templates`
uses. Two active transport allowances would both be copied onto the payslip and
the employee would draw it twice, an error nobody would notice because each row
looks correct alone.

The constants survive as the defaults a new employee is *enrolled on* at
registration. Nothing reads them at payroll time.

## Penalties can be recorded

`DeductionType::Penalty` had an enum case, an account mapping and a frontend
renderer, and no code path could create one. §11 lists it among the four
deduction types; the other three are computed from a rate or a balance, and a
penalty cannot be, because it is somebody's decision about somebody's conduct.

`staff_deductions` is period-scoped and never recurring — a recurring penalty is
a salary cut, and should be made as one. `reason` is required and not short:
this is money off a salary, and "penalty" is not something anyone can defend a
year later.

Recording a staff-fund, loan or advance deduction by hand is **refused**: it
would sit beside the computed one and deduct twice.

## Payroll gained an approval step

§16.1: *"Salary haiwezi kubadilishwa baada ya approval."* That sentence needs a
moment at which approval happened, and the run had none — HR generated a draft
that could be regenerated at will, and Finance posted whatever it held.

Four states now, each a different person's work:

| State | Who | What it does |
|---|---|---|
| `draft` | HR generates | Figures computed. Nothing agreed, nothing posted. |
| `approved` | **HR** — §16.7 | Figures close. Regeneration refused; no allowance or penalty may be recorded against the period. Still posts nothing. |
| `finalized` | **Finance** — §16.8 | Recognition and deduction entries post. |
| `paid` | Finance | Staff Payable settles to zero. |

Approval sits behind `payroll.generate` — HR's grant — deliberately. Putting it
behind `payroll.finalize` would hand both halves of §14's control to one role.

## Staff Fund and the §2B ledger views

§2B says registering an employee creates four accounts — Staff Control, Staff
Loan, Staff Advance, Staff Deductions. Spec §11 resolves how: *"no new physical
tables needed, staff_profile_id becomes a filterable dimension on
journal_entry_lines … views, not tables"*.

The dimension has existed since Phase 6 and been constrained since §2.9's
migration, and **nothing read it back per employee** — so the document's promise
that *"hakuna pesa ya staff inayoenda nje ya mfumo"* was true of the data and
invisible in the application. `StaffFundReader` is those views, and
`GET /staff/{staff}/ledger` publishes them.

Four real chart rows per employee was the alternative, and a worse one: a
hundred staff would mean four hundred rows that every report, trial balance and
account picker scrolls past, holding what the dimension already carries exactly.

### An unresolved ambiguity — for the owner

§12 heads its list **"📤 USAGE"** — the fund being drawn down — and then writes
the entry as *Dr Staff Advance / **Cr** Staff Fund*. Those two disagree:
crediting a liability raises it, so as written the fund **grows every time it
lends**. `7000 Staff Fund` therefore measures contributions *plus everything
ever lent*, not what is available to lend.

**Nothing was changed.** The postings are left exactly as the document writes
them: altering the direction would rewrite six modules' worth of settled ledger
history on one reading of one heading, and the trial balance is unaffected
either way — this is a question of what the balance *means*, not of whether the
books balance.

Two consequences, both deliberate:

- `StaffLoanAction::disburse` has **no "can the fund afford it" check**. Such a
  guard would compare an amount against a number that does not mean what the
  guard assumes.
- The Staff Fund report says so in its reconciliation note rather than implying
  a figure it cannot support.

If the intended reading is that lending draws the fund down, the change is two
lines in `PayrollPostingBuilder` and the guard becomes both correct and worth
having. **This is an owner decision.**

## Reports — all six of §17

| §17 asks for | Slug | Status |
|---|---|---|
| Payroll Report | `payroll` | already existed |
| Staff Payslip | `staff-payslip` | **added** |
| Commission Report (per branch) | `commission` | already existed |
| Staff Loan Report | `staff-loan` | **added** |
| Staff Advance Report | `staff-advance` | **added** |
| Staff Fund Balance | `staff-fund` | **added** |

`staff-payslip` differs from `payroll` by itemising: Payroll answers "what did
this run cost", a payslip answers "why is my pay this figure", which a column
called Deductions cannot.

Every one reads what the engine wrote rather than recomputing. A report that
disagreed with the payslip an employee was handed would be worse than no report.

## Permissions

| Ability | Permission |
|---|---|
| Read the staff book, allowances, deductions | `hr.view` |
| **Read payslips, the Staff Fund, a staff ledger** | `hr.view` **or** `payroll.finalize` |
| Register/amend staff, grant an allowance, record a penalty | `hr.manage` |
| Request/approve/reject a staff loan | `hr.manage` |
| **Disburse** a loan or advance | `payroll.finalize` — Finance, never HR |
| Generate **and approve** a payroll run | `payroll.generate` — HR |
| Finalize and pay a run | `payroll.finalize` — Finance |

The payslip row is a fix, not a preference. §14 gives Finance `payroll.finalize`
and **no HR grant at all**, and Finance is the role that posts and pays a
payroll. Gating a payslip behind `hr.view` would mean the person releasing the
money could not see what they were releasing, and Bank → Payroll — a Finance
screen — would answer 403 to the role it exists for. Writes stay HR's.

## Endpoints

| Method | Path |
|---|---|
| POST | `/payroll/{run}/approve` |
| GET | `/payslips?period=&staff_profile_id=&branch_id=` |
| GET | `/staff/{staff}/payslips` |
| GET | `/staff-fund` |
| GET | `/staff/{staff}/ledger` |
| GET / POST | `/staff/{staff}/allowances` |
| PUT / DELETE | `/staff-allowances/{allowance}` |
| GET / POST | `/staff/{staff}/deductions` |
| DELETE | `/staff-deductions/{deduction}` |
| POST | `/staff/loan/{request,approve,reject,disburse}` |

## Frontend

| Screen | Change |
|---|---|
| Bank → Payroll, and its payslip detail | Off `MOCK_PAYROLL_ROWS` entirely — real payslips, real payment history |
| HRM → Payroll (list and period) | Approval control; actor names resolved server-side, `MOCK_USERS` gone |
| HRM → Performance | Recorder resolved server-side, `MOCK_USERS` gone |
| HRM → Staff Loan | Reference, recovered, outstanding and next instalment |
| HRM → **Staff Fund** | New screen — §12's fund and what it has lent |

`PAYROLL_RUN_STATUSES` and `STAFF_LOAN_STATUSES` both gained states. That is a
frontend change driven by the documentation rather than by an incomplete
backend: neither enum could express a step §16.1 and §16.7 require.

Two payslip decisions worth stating:

- **A payslip has no status of its own.** §11 pays a run as one act, so the pill
  reads off the run. A per-employee payment state would imply the company can
  pay half a payroll.
- **The payment history's reference is the journal entry.** A payslip carries no
  document number, and inventing one would print a reference nothing in the
  ledger answers to.

## Factories

None added. Every HR entity is reachable through its own workflow — a loan
through request/approve/disburse, an allowance through a grant — and the tests
use those rather than constructing rows, which is what makes them tests of the
workflow rather than of the table.
