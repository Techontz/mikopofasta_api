# Module: Salary Advance

Sidebar → **Salary Advance**: Salary Advance Category, Request, Approved,
Active, Repayment, Paid List. HRM → *Staff salary advance category* reaches the
same register.

## What was already here, and what was wrong with it

`staff_advances` existed with §11's lifecycle on it — request → HR approval →
**Finance** disbursement — and that separation was correctly enforced. What it
recorded was an amount and nothing else.

Two defects followed from that, and both took real money:

**1. Recovery was a flat 50,000 for everyone.**
`PayrollCalculator::RECOVERY_PER_PERIOD` deducted the same figure regardless of
what had been borrowed. Its own docblock conceded the number was picked rather
than derived, because §11 says an advance is "recovered automatically from
payroll" without giving a schedule.

**2. An advance never finished.**
Nothing anywhere set `StaffAdvanceStatus::Recovered`. A disbursed advance stayed
outstanding for ever, `hasOutstandingAdvance()` kept returning true, and payroll
deducted 50,000 every month indefinitely — from someone who had already repaid.

The category is what fixes both, which is why it is the centre of this module
rather than a settings screen bolted to the side.

## Categories

`salary_advance_categories` — name, interest rate, an amount band, a charge fee,
and **`recovery_periods`**.

A request does **not** choose its category; the amount does, by falling inside a
band. Letting the requester pick would let them pick their own interest rate,
and two employees borrowing the same amount would be on different terms.

**Bands must not overlap.** Two bands covering one amount would price the same
request two ways depending on which was matched, and the tie-break would be row
order — which nobody intends as a pricing policy. Refused at creation and on
edit. Bands that *touch* (one ending where the next begins) are fine, and must
be: refusing them would force a gap no request could be priced in.

`recovery_periods` is not on the frontend's `SalaryAdvanceCategorySchema`,
because the fixture that screen ran on had no notion of a repayment schedule. It
is what turns a band into terms rather than a price list, so it was added to the
form.

### Provenance of the seeded bands

The legacy category screen was **not captured**, so unlike the loan fee's 5%
there is no transcribed figure to reproduce. What is known from the captures is
the *shape* — the five columns came off the legacy form. `SalaryAdvanceCategorySeeder`
therefore seeds a plainly conservative ladder and says in its docblock that the
values are placeholders. Replace them the moment the real bands are known;
nothing else depends on the numbers, because terms are snapshotted per advance.

## Pricing and terms

At **request**, the band's terms are snapshotted onto the advance —
`interest_amount` (money, not a rate), `charge_fee`, `recovery_periods` — the
same rule loans and penalties follow. Re-pricing a band later changes what
future requests cost and never rewrites an advance already agreed.

| Figure | Meaning |
|---|---|
| `amount` | the principal |
| `interest_amount` | simple interest on the principal, charged once |
| `charge_fee` | flat processing charge |
| **total repayable** | the three added |
| `amount_recovered` | what payroll has taken so far |

Interest is charged **once**, not per period: the legacy screens print
"Interest" as a single figure beside the principal and never show it accruing,
so compounding it over the term would invent a pricing model those screens
contradict.

## Recovery

The instalment is the total repayable spread across `recovery_periods`, then
**capped at what is still owed**.

- Divided from the *total*, not from the remainder — dividing the remainder each
  time shrinks the instalment asymptotically and the advance never clears.
- `allocate()`, not `divide()`. Dividing 100.00 over three periods gives 33.33,
  and three of those leave a cent outstanding, so the advance would run a fourth
  period to collect it. Allocating puts the remainder cents in the earliest
  instalments, so the agreed term is the actual term.
- The cap is what makes the final instalment exact and stops an almost-cleared
  advance being over-recovered.

`RecoverStaffAdvanceAction` credits the advance after the deduction has
**posted** — at finalisation, never at generation — so an advance's balance can
never run ahead of the ledger that recovered it. It closes the advance when the
balance clears.

Staff **loans** still use the flat `RECOVERY_PER_PERIOD`. They have no category
to derive terms from; the constant survives for them and is documented as such.

## Ledger

| Event | Debit | Credit |
|---|---|---|
| Disbursement | `7020` Staff Advance Receivable — principal | `7000` Staff Fund — principal |
| Recovery | `7050` Staff Payable — instalment | `7020` — **principal portion** |
| | | `7000` Staff Fund — **interest + fee** |

### Why the recovery credit splits

Crediting the whole instalment to `7020` — which is what the code did before
this module — is wrong in a way the trial balance cannot see.

Disbursement debits `7020` with the **principal**. An instalment recovers
principal *plus* interest *plus* charge fee. Crediting all of it to `7020` drives
the receivable **below zero** by exactly the charges: a seeded advance finished
at **−9,500** on that account, asserting the company owed the employee money it
did not, and recognising the charges as income nowhere at all. Both sides of the
entry moved together, so the books still balanced — which is why nothing caught
it.

The principal portion clears the receivable it created. The charges credit
`7000` Staff Fund, which is where §12 puts them: the fund is an internal
revolving one that *"inazalisha faida ndani yake"* — generates its profit within
itself — so what an advance earns returns to the fund the staff collectively own
rather than to company income they have no claim on.

The split is **principal-first**, computed from the advance's cumulative
recovery rather than per instalment, so rounding cannot accumulate and `7020`
decreases monotonically to zero and never below.

`RecoverStaffAdvanceAction` itself **posts nothing**. The payroll deduction
entry already moved the money; posting again would credit `7020` twice and the
advance would appear to repay itself at double rate.

## Screens

| Screen | Source |
|---|---|
| Salary Advance Category | `salary_advance_categories` |
| Request / Approved / Active / Paid List | `staff_advances`, filtered by status |
| Repayment | `deductions` of type `advance` |

Repayment reads deductions rather than `amount_recovered`: that column is a
running total and cannot say *when* any of it was taken, and the screen has a
date column. The deduction rows are also what the payroll entry posted against.

### Status vocabulary

The frontend says `active` and `repaid`; §11 and the enum say `disbursed` and
`recovered`. Both are right for their own side — the backend describes what
happened to the money, the screens describe what the employee sees — so they are
**mapped**, not made to give way. The API accepts and returns the frontend's
words and accepts the backend's too, so an API caller using §11's language is
not turned away.

## Permissions

| Ability | Permission |
|---|---|
| Read any Salary Advance screen | `hr.view` |
| Create / edit / retire a band | `hr.manage` |
| Request, approve, reject | `hr.manage` |
| **Disburse** | Finance — `payroll.finalize`, never HR |

§11 is emphatic that HR approves and Finance disburses. That is enforced by two
different permissions on two different endpoints, not by hiding a button.

## Two frontend behaviours removed rather than reproduced

**Edit paid amount.** The fixture panel let someone type over an advance's paid
figure. Recovery is a payroll deduction that posts to `7020`; a hand-typed
figure would put the screen at odds with both the payslip that took the money
and the ledger that recorded it. The Repayment screen shows what was actually
recovered, instalment by instalment.

**Delete an advance.** A disbursed advance has a journal entry behind it. A
request is closed by **rejecting** it, which leaves the record — which is what
an audit trail is for.
