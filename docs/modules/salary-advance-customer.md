# Module: Salary Advance — Customer

Sidebar → **Salary Advance** → the customer register, beside the staff one.
Priced from the same bands; funded, collected and reported differently.

## The rule this module implements

The client stated it twice, and the second statement is the one that decides the
design. First, the money:

> SALARY ADVANCE CUSTOMER WAKATI WA MAOMBI ITATOKA KWENYE PRINCIPAL OPERATION NA
> WAKATI WA MALIPO SEHEMU YA MTAJI ITARUDI PRINCIPAL OPERATION NA FAIDA ITAINGIA
> INCOM OPERATION.

Then, when asked whether Salary Advance holds a balance of its own:

> The Salary Advance section on the dashboard pop up branch list is only a
> summary/monitoring view, **not a separate cash account or balance**. […] there
> is no TZS 1,000,000 sitting in a "Salary Advance Account". […] **Salary Advance
> = dashboard summary + transaction history, NOT a separate cash account.**
> Actual money must always end up in the correct accounts: **Principal →
> Operational Principal, Profit → Operating Income.**

Their worked example, which `CustomerAdvanceTest` asserts to the shilling:

| Event | Figure |
|---|---|
| Advance issued | 1,000,000 out of operational principal |
| Customer pays | 220,000 in a month |
| → capital | 200,000 back to operational principal |
| → profit | 20,000 to operating income |
| Dashboard shows | **Salary Advance Payments — 220,000**, a summary only |

## The ledger

| Event | Debit | Credit |
|---|---|---|
| Issue | `1250` Salary Advance Receivable — principal | Bank / Branch Teller Cash — principal |
| Payment | Bank / Branch Teller Cash — collected | `1250` — capital portion |
| | | `2000` Interest Income — interest portion |
| | | `2100` Fee Income — charge-fee portion |

### Why nothing posts to 1100 Principal

"Principal Operation" reads like the 1100 Principal account, and that reading is
wrong in a way that costs money. **1100 is EQUITY** — it holds reinvested profit
— and crediting it at disbursement is exactly the double count this ledger
already removed once: the money is still in the bank *and* now a receivable as
well. `SystemAccountCode::type()` carries that history.

What the client means by Principal Operation is the operational money the
business lends from, which in this chart is the bank account or the branch till
the payout actually leaves. So the capital leaves it and returns to it, exactly
as stated, and equity moves only when profit is earned — which is the second
half of the same sentence.

`funding_account_id` records which real account each advance left, so "no
balance sits under Salary Advance" is checkable rather than merely asserted.

### Why 1250 exists at all

A receivable is not a cash pot. It is the debt the customer owes, it is created
by the issue and destroyed by the payments, and it reads zero for a customer who
owes nothing. Without it the issue has no debit and the books do not balance.

It is separate from `1200` Loan Receivable because an advance is not a loan — no
schedule, no penalty, no mandate — and merging them would make "what is out on
loan" unanswerable.

## How a payment is split

Pro rata across the three things the advance is made of, which is the client's
example: 1,000,000 principal plus 100,000 interest is 1,100,000 repayable, and
220,000 of that carries 200,000 of capital and 20,000 of interest.

The split is computed from the **cumulative** total paid and then reduced by what
has already been credited. Splitting each payment on its own rounds every time,
and `1250` finishes a cent or two from zero on a settled advance with nothing to
say which payment did it. Cumulatively, the last payment's share is whatever is
left, and the receivable closes exactly — asserted by the "clears the receivable
to exactly zero" test, which pays three uneven amounts.

The fee share is the **residual** of the other two rather than a third
proportion, so the parts always add back to the payment.

## Overpayment is refused

A loan banks a surplus as a customer advance credit, because it has later
instalments for the money to sit against. An advance has one balance. Money
accepted past it would be a liability to the customer with no record built to
hold it, so `CollectCustomerAdvanceAction` refuses it and says what is
outstanding.

## Branch List — one month at a time

`GET /dashboard/branch-summary?month=YYYY-MM`, defaulting to the month in
progress. The client's instruction:

> KWENYE Branch List — September 2026 ITAREKODI KIASI KILICHOLIPWA KWA UJUMLA NA
> ITAKUWA INAONEKANA HAPO HADI MWISHO MWA MWEZI NDIPO TAARIFA NYINGINE ZA MWEZI
> MPYA ZITAKUWA ZINAJIREKODI.

So the popup reads a month, it stands until the month ends, and the next month
starts recording its own. Every column is money that **arrived** between the
first and the last day of that month; nothing carries over. Nothing is lost
either — last month is one parameter away, and every event is in the ledger and
in Reports.

| Column | Counted from |
|---|---|
| Principal A/c | credits less debits on `1200` + `1250` |
| Interest A/c | `2000` |
| Loan fee A/c | `2100` |
| Penalty A/c | `2200` |
| Reserve A/c | `3000` |
| **Salary Advance** | `customer_advance_payments.amount` for the month |

Two details that decide whether the figures are right:

**Only inflow entries count.** `1200` is debited by every disbursement and
credited by every repayment, so a plain net movement would report a busy lending
month as negative collections. The source-type filter admits `repayment`,
`suspense_resolution`, `advance_consumption`, `customer_advance_payment` and
`reserve_appropriation`, and nothing else. Period-closing entries are
deliberately excluded: the close sweeps income into Profit by *debiting* 2000,
2100 and 2200, and counting that would report every closed month as having
collected nothing.

**Reversals count as what they reverse** — `COALESCE(orig.source_type,
e.source_type)`. A reversal's own source type belongs to no bucket, so left as
itself it would be dropped and a reversed collection would stay in the month's
total for ever.

**The branch comes from the LINE, not the account.** System accounts are
company-wide rows with `branch_id` null; grouping by the account's branch would
put every shilling of income in one unnamed bucket.

### The Salary Advance column is a summary of money already counted

An advance payment's capital is in the Principal column and its profit is in
Interest and Loan fee. The response says so in `salaryAdvanceIsSummary` so the
dialog can print the caveat, rather than leaving somebody to add the row across
and find it does not foot. This is the client's own framing: a summary and a
transaction history, not a pot.

## Lifecycle and permissions

request → approve → **disburse** (the money moves here and nowhere earlier) →
collected until settled.

| Ability | Permission |
|---|---|
| Read the register | `loans.view` |
| Raise a request | `loans.create` |
| Approve / reject | `loans.approve` |
| **Disburse** | `loans.disburse` |
| Take a payment | `repayments.cash_entry` **or** `repayments.manage` |

**No new permission strings.** A salary advance is the company lending its own
operational money to a customer and collecting it with interest, which is what
the loan grants already describe — so nobody acquires a power by this module
existing, and no role matrix had to change.

Approving and disbursing stay separate grants. That is the control that matters:
whoever says the advance is warranted must not also be the person who pays it
out.

Collection accepts either `repayments.cash_entry` or `repayments.manage`.
Requiring cash entry alone would leave a bank or mobile-money collection with
nobody able to record it.

## Pricing

One band ladder, `salary_advance_categories`, shared with the staff register —
the legacy menu has exactly one Salary Advance Category screen, and pricing the
same product from two tables is how the two drift apart.

The band is found from the **amount**, never chosen by the requester: letting
them pick the category would let them pick the interest rate, and two customers
borrowing the same amount would be on different terms. Terms are snapshotted at
request, so re-pricing a band changes only future advances.

Interest is charged **once**, not per period, and the charge fee is part of what
is collected rather than withheld at disbursement — both the same as the staff
advance, and both the opposite of how a loan fee works.

## One advance at a time

Two running together would each be collected on their own terms against the same
salary, and the second would be lending against money the first has already
claimed. Refused with 409.
