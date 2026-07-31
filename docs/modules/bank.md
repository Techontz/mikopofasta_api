# Module: Bank

Sidebar → **Bank**: Register Account, Account Balance, Bank Transaction,
Approved Transaction, Transfer Balance / Branch Account, Transfer Balance /
Salary Advance & Disbursement Account, Register Bank Expenses, Request Expenses,
Payroll.

Nine menu entries, three of which are served by other modules — see *What this
module does not own* at the end.

## What was already here

`bank_accounts` existed, seeded and read but never written. Its own migration
says why: "the frontend has no bank-account CRUD screen (readiness report gap
3), so this is seeded and read, never managed through an endpoint."

That gap is closed. The rebuilt frontend has Register Account, and the table
grew the four fields that form collects and it lacked: `branch_id`, `currency`,
`opening_balance` and `description`.

## Opening balance is not a balance

`opening_balance` records what an account held when it was registered. The
**balance** is the 8xxx chart account's, derived from journal lines like every
other balance in this system — so the Account Balance screen can show what an
account started with beside what it holds now, and the second figure is always
the ledger's answer.

Registering an account with an opening balance therefore **posts**:

| Debit | Credit |
|---|---|
| the new 8xxx bank account | `1000` Capital |

Capital, because money that exists at the moment an account is opened came from
the owners rather than from operations. Booking it anywhere else would either
invent income the company never earned or leave the trial balance one-sided.
§5's rule is that every shilling passes through the ledger, and an opening
balance is not an exception to it.

`opening_balance` is **not editable**. It is a figure an entry already posted,
and changing the number without reversing the entry would put the account's own
screen at odds with the ledger. Correcting one means reversing that entry, which
is the Ledger module's job.

## Chart account codes

Every bank account owns one 8xxx chart account (§2.2). `ChartOfAccountSeeder`
allocates them at 8000 and 8010 — sequential in tens — and `BankAccountResolver`
continues that rather than inventing a second scheme for accounts created
through the UI, so the chart reads the same however a row got there.

The account is not branch-scoped: one bank account can serve several branches,
and the branch dimension on each journal line separates their activity on it.

The chart account follows the bank account's status. Deactivating one
deactivates the other, and `LedgerService` refuses to post to an inactive
account — which is what makes "inactive" mean the account cannot be used rather
than merely being badged on a table row.

## Closing an account

Soft-deletes the record, deactivates the chart account, and **never** deletes
the chart account: it holds every shilling that ever passed through.

Refused while the account still holds a balance, or while a transaction or
transfer against it is awaiting a decision. Both are the same objection —
closing an account out from under live money loses track of it, and no later
reconciliation recovers from that.

## Bank transactions

One table, two screens (Bank Transaction and Approved Transaction), for the same
reason the expense queues share one: an approved transaction is not a different
record from a pending one.

Pending posts nothing. Approval posts:

| Type | Debit | Credit |
|---|---|---|
| `deposit` | bank account | branch teller cash |
| `withdrawal` | branch teller cash | bank account |
| `charge` | `6150` Bank Charges | bank account |
| `transfer` | `7200` Offset | bank account |

**Deposits and withdrawals require a branch** — without one there is no till for
the cash to move to or from, and the movement is refused rather than posted
against a guess.

**`6150` Bank Charges is a new fixed account.** A bank's fee is an operating cost
of the company as a whole; booking it to `4200` Write-Off would overstate credit
losses by the price of running a bank account. It is fixed rather than a dynamic
6200-range expense category because every charge and every transfer fee lands
there automatically, and an administrator must not be able to retire the account
they land in.

**`transfer` parks against `7200` Offset.** Money leaving for a destination this
record does not name — the Transfer Balance screens exist to name it, so a
transfer raised here has only one known side. §5 lists Offset for exactly this
kind of adjustment, and a balance sitting there is a visible prompt to resolve it
rather than a silently wrong attribution.

**Overdrawing is refused.** The ledger would permit a negative asset balance; a
real bank account would not, and reporting money the company does not have is
worse than refusing the movement. The request stays `pending` so it can be
corrected rather than lost.

§14 separation of duties applies: the requester may not approve their own
transaction.

## Bank transfers

Both Transfer Balance screens, one table. They differ only in where the money
goes, which is why there are two destination columns and each kind uses exactly
one:

| Kind | Destination |
|---|---|
| `branch` | `to_branch_id` — the branch's teller cash |
| `salary_advance` | `to_account_id` — another bank account |

**A transfer applies immediately and is recorded `completed`.** The legacy
screens show no approval step for either, and both are one person moving the
company's own money between its own accounts — the same reasoning that lets
company-to-branch float apply without a second signature.

The posting, for a transfer of X with a charge of F:

| Debit | Credit |
|---|---|
| destination (till or account) — X | source bank account — X + F |
| `6150` Bank Charges — F | |

**The charge is a separate debit, not netted off the amount.** The destination
receives what was sent, and the cost of sending it stays visible as a cost.
Netting would hide the fee inside the transfer and leave nothing on the P&L to
show what banking actually costs. A zero charge posts two lines rather than
three — a zero-amount line is not a line, and `LedgerService` rejects one.

The source must cover **amount plus charge**, since both leave it.

`bank_transfers.status` uses the frontend's own vocabulary — `pending` /
`completed` / `cancelled`, not `approved` / `rejected` — because those screens
describe a movement being carried out rather than a request being granted.

## Permissions

| Ability | Permission |
|---|---|
| Read any Bank screen | `treasury.view` |
| Register / edit / close an account | `treasury.manage` |
| Raise a transaction | `treasury.manage` |
| Approve / reject a transaction | `treasury.manage` + **not the requester** |
| Make a transfer | `treasury.manage` |

Enforced by `CapitalPolicy` — the same pair Capital and Headquarters use,
because the frontend gates all three sections on `treasury.view`. There is no
`decideBankTransfer`: transfers have no approval step.

## Today's movement

`GET /bank-accounts?with_movement=1` adds `todayDeposit` and `todayWithdrawal`.

These are read **from the journal**, not from `bank_transactions`. A bank account
is also credited by loan disbursement and debited by repayment, and a screen
showing only what this module recorded would understate the day by everything the
rest of the system did. A debit to an asset account is money in; a credit is
money out.

Opt-in because it costs a grouped query over the day's lines, computed once for
the whole page rather than once per row.

## What this module does not own

Three of the nine menu entries are served elsewhere, deliberately:

- **Register Bank Expenses** and **Request Expenses** are the Expenses module.
  A bank-paid expense is the same record, the same approval, and only the credit
  side of the posting differs — so `expense_requests` gained a nullable
  `bank_account_id` rather than growing a second expense system. Two tables would
  have meant two Expense Tagging Reports and two chances for them to disagree.
- **Payroll** is the HR module's `payroll_runs` / `payroll_lines`, read from the
  Bank menu because that is where the legacy system files it.
