# Module: Capital

Sidebar → **Capital**: Share Holders, Add Capitals, Float, Float Branch To Branch,
Aproved Float, Float Ac-Ac.

Workflow is the legacy system's, taken from its six screens. Engineering is this
codebase's: Actions + Services, one posting engine, policy-gated writes.

## Screens and their legacy routes

| Legacy | This system | What it does |
|---|---|---|
| `/admin/shareHolder` | `/admin/capital/shareholders` | Register a shareholder; list, edit, delete |
| `/admin/capital` | `/admin/capital/contributions` | Record capital against a shareholder; list with two totals |
| `/admin/transfar_amount` | `/admin/capital/float` | Company → branch float; today's transfers |
| `/admin/float_branch_branch` | `/admin/capital/float-branch` | Branch → branch, raised PENDING |
| `/admin/aproved_float` | `/admin/capital/float-approved` | The approved ones, with a total |
| `/admin/float_branch_ac_ac` | `/admin/capital/float-accounts` | Account → account within a branch |

## Schema

### `shareholders`
`full_name`, `phone` (unique), `email` (unique), `gender`, `date_of_birth`.
Soft-deleted, because a contribution must keep pointing at whoever made it.

### `capital_contributions`
`shareholder_id`, `amount`, `pay_method` (`cash` | `cheque` | `bank_transfer`),
`receipt_no`, `cheque_no`, `journal_entry_id`.

The legacy form has no branch field, so none is added: cash lands at head
office, which is read from `company_profiles.headquarters_branch_id`.

### `float_transfers`
One table for all three float screens, because all three are the same event —
money moving between two ledger accounts — differing only in which accounts and
whether approval is required.

| Column | Notes |
|---|---|
| `kind` | `company_to_branch` \| `branch_to_branch` \| `account_to_account` |
| `from_branch_id` / `to_branch_id` | Nullable; what the screens display |
| `from_account_id` / `to_account_id` | Always set — this is what actually moves |
| `amount`, `status` | `pending` \| `approved` \| `rejected` |
| `requested_by`, `approved_by`, `approved_at`, `rejection_reason` | |
| `journal_entry_id` | Set when it posts; null while pending or rejected |

## Ledger postings

Every movement goes through `LedgerService::post()`, the only code path allowed
to write journal lines (§5). Nothing here inserts lines directly.

| Event | Debit | Credit | Source type |
|---|---|---|---|
| Capital injection, cash | HQ teller cash `1500-1` | Capital `1000` | `capital_injection` |
| Capital injection, cheque/bank | The company account named (or default bank `8000`) | Capital `1000` | `capital_injection` |
| Float company → branch | Destination teller cash | HQ teller cash | `transfer` |
| Float branch → branch *(on approval)* | Destination teller cash | Source teller cash | `transfer` |
| Float account → account | To account | From account | `transfer` |

The cash-vs-bank choice reuses `AccountResolver::cashAccountFor()`, so capital
lands where a cash repayment would.

`JournalSourceType::Transfer` is new. The dashboard already groups by it — its
"Transfer" row has been reading zero because nothing emitted one.

**A pending transfer posts nothing.** Money moves on approval, not on request,
so a queue of pending transfers never affects the trial balance.

## Approval (§14 separation of duties)

Branch → branch is raised `pending` and needs a second person: `approve` and
`reject` are gated on `treasury.manage`, and **the requester may not approve
their own transfer**, the same rule loan approval follows.

Company → branch and account → account apply immediately — the legacy screens
show no status for either, and both are one person moving the company's own
money between its own tills.

## Permissions

| Ability | Permission |
|---|---|
| Read any Capital screen | `treasury.view` |
| Register/edit/delete a shareholder | `treasury.manage` |
| Record capital | `treasury.manage` |
| Raise a float transfer | `treasury.manage` |
| Approve / reject | `treasury.manage` + not the requester |

## Totals

The legacy Add Capital screen shows two:

- **SHARE HOLDER CAPITAL** — the sum of `capital_contributions`.
- **TOTAL COMPANY CAPITAL** — read from ledger account `1000`, not from the
  contributions table. The legacy screen shows `38,000,000` against `0` for
  exactly this reason: they are different questions, and reconciling them is
  the point of showing both.

## Money trail

Shareholder → Capital Contribution → Company account → Transfer → Loan
Disbursement → Customer / Loan. Every step is its own transaction, with its own
reference and its own journal entry.

| Step | Record | Reference | Entry | Posting |
|---|---|---|---|---|
| Shareholder | `shareholders` | — | — | — |
| Contribution | `capital_contributions` | `CAP-0000001` or the payer's transaction number (UNIQUE) | `capital_injection`, `source_id` = contribution | Dr the account it landed in · Cr `1000` Capital |
| Capital → Bank/Cash | `bank_transfers` / `float_transfers` | `TRF-0000001` | `transfer` | Dr destination · Cr source |
| Disbursement | `disbursement_batches` | `batch_reference` | `loan_disbursement`, `source_id` = loan; `batch.journal_entry_id` | Dr `1200` Loan Receivable · Cr the funding Bank/Cash account (net) · Cr `2100` Fee Income (fee) |

Money is fungible, so the trail is joined by **accounts**, not by lots: the
contribution's `received_account_id` is the account a transfer draws from, and
the transfer's destination is a batch's `funding_account_id`. Every disbursement
line also carries `customer_id` and `loan_id`.

### Contributions

`POST /capital-contributions` accepts, beyond the legacy five fields:

| Field | Notes |
|---|---|
| `bankAccountId` | A registered company account that may receive money. Refused for cash, which lands in the head-office till. Omitted: the default bank account. |
| `reference` | Optional; allocated as `CAP-{id}` when blank. UNIQUE across every contribution ever recorded, removed ones included. |
| `sourceAccountName`, `sourceAccountNumber` | The shareholder's own account the money came from. |

A duplicate reference is refused (422, or 409 `DUPLICATE_TRANSACTION` when two
identical requests race). The route also honours `Idempotency-Key`.

### Moving capital to operations

A transfer between company accounts is an internal movement: `source_type =
transfer`, nothing is credited to `1000`, and no contribution is created. The
contributed-capital total and every ownership percentage are unchanged by it.

### Disbursement

`POST /loans/{loan}/prepare-disbursement` accepts `fundingSource` (`account`,
the default, or `cash` for the loan branch's till) and `fundingBankAccountId` (a
registered account that may send money; omitted = the first such account). The
choice is stored on the batch, and a retry reuses it.

Preparation is **refused** when the account's ledger balance, less what other
pending batches already have in flight from it, cannot cover the net payout. It
is refused there — before anything goes to a provider — rather than at
settlement, because a provider confirming money already sent must be recorded.

Settlement locks the loan and batch, posts, links the entry to the batch and
activates the loan in one transaction. If the posting fails, nothing commits:
the batch stays `pending` and the loan stays `awaiting_disbursement`. A repeated
callback finds the batch settled and gets 409; `settled_loan_id` is UNIQUE, so
the database refuses a second successful batch for the same loan outright.

**Changed posting.** Disbursements used to post Dr Loan Receivable · Cr `1100`
Principal, which never reduced any Bank/Cash account — the cash and the loan
book were both on the books for the same money. They now credit the funding
account. Principal still receives reinvested profit at month-end close. Entries
posted before this change are not rewritten — see *Historical disbursements*
below.

### Historical disbursements — REQUIRES A SEPARATE ACCOUNTING DECISION

Every disbursement settled **before** migration
`2026_09_13_000001_add_money_trail_to_capital_and_disbursements` was posted as:

    Dr 1200 Loan Receivable · Cr 1100 Principal (· Cr 2100 Fee Income)

Those entries are **incorrect and have been left untouched on purpose.** No
Bank/Cash account was ever credited for them, so for each one:

- the funding Bank/Cash account is overstated by the net payout, and
- `1100` Principal (equity) is overstated by the same amount.

The trial balance still balances; the balance sheet does not reflect reality.

Nothing in this release reverses, rewrites, or "corrects" them, and no opening
balance or capital was invented to compensate. Correcting them means deciding,
per loan, which real account actually paid out — information the old records do
not hold — and then posting dated adjusting entries (Dr 1100 Principal · Cr that
account) through the normal reversal/adjustment approval. That is an accounting
decision for Finance and the auditors, not a code change.

To list the affected entries (read-only):

```sql
SELECT je.entry_number, je.entry_date, je.source_id AS loan_id, jl.credit_amount
FROM journal_entries je
JOIN journal_entry_lines jl ON jl.journal_entry_id = je.id
JOIN chart_of_accounts coa ON coa.id = jl.account_id AND coa.code = '1100'
WHERE je.source_type = 'loan_disbursement' AND je.is_reversal = 0
ORDER BY je.entry_date;
```

The migration only links those old batches to their existing entry
(`journal_entry_id`, `settled_loan_id`); it does not create, change or reverse
any journal entry.

### Production prerequisite

Disbursement preparation now refuses a payout the chosen account cannot cover
from its **ledger** balance. Real money held in company accounts must therefore
be in the ledger — through real capital contributions, registered opening
balances, or recorded receipts — before disbursements can be prepared. Do not
post fictitious capital or opening balances to get past the check.

`GET /loans/{loan}/disbursements` lists every attempt with its funding account
and, for the successful one, its entry and lines.

## Ownership

    share % = shareholder's cumulative contributions ÷ all contributions × 100

Read from `capital_contributions` only — never from account `1000`, a bank
balance, profit, or the loan book — so spending, lending and earning never move
it. A removed contribution (reversed in the ledger) is a correction and is
excluded. Rounded half-up to two places in integer minor units
(`ShareholderOwnership`); rounded shares may sum to 99.99 or 100.01.

- `GET /shareholders` — each row adds `totalContributed` and
  `ownershipPercentage`; `meta.totalContributed` is the denominator.
- `GET /shareholders/{shareholder}` — the same, plus `meta.contributions`: the
  full history with references, receiving account, entry number and recorder,
  removed entries flagged by `removedAt`.
