# Module: Headquarters Transaction

Sidebar → **Headquarters Transaction**: Headquarters Account Balance, Requested
Transactions, Approved Transactions.

## Why this is not the chart of accounts

The legacy headquarters module is a small internal ledger over seven named pots
— SALARY ADVANCE, DISBURSEMENT, PENALTY, INTEREST, RESERVE, LOAN FEE and SAVING
— with its own transfer screens. Two of the seven (DISBURSEMENT and SAVING) have
no §5 counterpart at all, so folding them into `chart_of_accounts` would have
meant inventing two system codes or dropping two accounts.

It therefore keeps its own tables, and **nothing here posts to the §5 ledger.**
That has one important consequence: since no journal entry records a headquarters
movement, the **audit trail is the only record of who moved what** — which is why
every request and every decision is logged.

## What was known, and what was not

`hq_accounts` is a transcription. The seven balances come from the legacy
Headquater Account Balance screen and sum to exactly the 8,667,270 that screen
prints; `HqAccountSeeder` asserts that and refuses to run if it ever stops being
true.

`hq_account_transfers` was **not**. Both legacy transaction screens were captured
with no rows in them, so the original table recorded the columns those screens
have — Charger, Staff Name, status — without knowing what any of them contain.

The rebuilt frontend then designed the screen properly (`HqTransactionSchema` in
types/operations.ts) and asked for five things that table had no room for: the
branch a movement relates to, who requested it, who approved it, the reason, and
whether it adds to the headquarters position or draws it down. The frontend is
the source of truth for this rewrite, so the table grew to fit it.

Kept rather than dropped: `staff_name` and `charger`. They are the legacy
columns, and imported legacy rows have to land somewhere. Nothing in this
application writes them; new records name a real user in `requested_by`.

Invented, and flagged as such: **`direction`**. No captured screen has one. It
exists because the frontend's `hqBalance()` cannot tell income from expenditure
without it.

## Schema additions

| Column | Notes |
|---|---|
| `reference` | `HQT-0000001`, unique |
| `direction` | `in` \| `out` \| `internal` |
| `branch_id` | Nullable — the branch a movement relates to |
| `reason` | Required on new records |
| `requested_by` / `approved_by` | Real users; null on imported legacy rows |
| `from_account_id` / `to_account_id` | Both now **nullable** — see below |
| `deleted_at` | |

## Directions and sides

Each direction names the sides it has, and the rule is enforced in
`RequestHqTransactionAction` rather than only in the form request — it is a
property of the record, not of one HTTP payload, and the seeder must obey it too.

| Direction | Sides | Effect on the headquarters total |
|---|---|---|
| `in` | `to_account_id` only | Increases |
| `out` | `from_account_id` only | Decreases |
| `internal` | both | **None** |

`internal` is the legacy module's original purpose: money moved between two of
the seven pots. It changes which pot holds the cash and not how much there is,
which is exactly why the position calculation counts only `in` and `out` — and
why the account balance screen's total is unchanged by one.

## Balances

`hq_accounts.balance` is **stored**, not derived. This is the one place the
system departs from how the rest of it works, and the reason is evidential: the
seven legacy balances are known but the transfers that produced them are not, so
a derived balance would have to start from zero and disagree with the legacy
system on day one.

Two things follow:

- **Approval mutates the balance directly** — debit the source pot, credit the
  destination, whichever exist. Rows are locked `FOR UPDATE`, because two
  approvals landing together could otherwise both read the same starting balance
  and each write it back, and with a stored balance there is no journal to
  reconcile the loss against afterwards.
- **A pot cannot be overdrawn.** A derived balance cannot go wrong without an
  entry explaining it; a stored one can, so refusing the movement is the only
  protection there is. The request stays `pending` when refused, so it can be
  corrected rather than lost.

A pending movement changes nothing — money moves on approval, the same rule
branch-to-branch float and expense approval follow.

When the legacy transfer history is eventually captured, `balance` can become a
projection over `hq_account_transfers` and both of the above become unnecessary.

## Status

`status` stays a VARCHAR rather than a database ENUM. The legacy vocabulary was
never captured, and constraining the column would freeze a guess into the schema.
`HqTransactionStatus` supplies the three the rebuilt frontend uses — `pending`,
`approved`, `rejected` — for new records; imported legacy values will still fit
the column, making them a data question rather than a migration.

## Permissions

| Ability | Permission |
|---|---|
| Read balances and transactions | `treasury.view` |
| Raise a movement | `treasury.manage` |
| Approve / reject | `treasury.manage` + **not the requester** |

The same pair Capital uses, enforced by `CapitalPolicy` —
`decideHqTransaction()` is a separate method from `decide()` rather than a union
type, because the two are different records and a policy accepting either invites
a caller to pass the wrong one to the wrong screen's check.

§14 separation of duties applies. Note that `requested_by` is null on imported
legacy rows, which cannot fail the self-approval test — correct, since a row from
the old system was by definition raised by someone who is not the current user.

## Endpoints

| Method | Path | Screen |
|---|---|---|
| GET | `/hq-accounts` | Headquarters Account Balance |
| GET | `/hq-transactions` | Requested / Approved (one collection, filtered) |
| POST | `/hq-transactions` | Raise a movement |
| POST | `/hq-transactions/{transaction}/decide` | Approve or reject |

`GET /hq-transactions` returns `meta.income`, `meta.expense` and `meta.net`,
computed the same way the frontend's `hqBalance()` does. They are returned rather
than left to the client so that a filtered list and its own summary cannot drift
apart.
