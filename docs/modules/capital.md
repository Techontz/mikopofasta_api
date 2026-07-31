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
| Capital injection, cheque/bank | Default bank `8000` | Capital `1000` | `capital_injection` |
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
