# Module: Expenses

Sidebar → **Expenses** (Register Branch Expenses, All Expenses Request, All
Approved Expenses) and **Headquarters Expenses** (Register Expenses, All
Expenses Requested, All Approved Expenses), plus Settings → Expense Categories.

Seven screens, two tables. Workflow is the legacy system's; engineering is this
codebase's — Actions + Services, one posting engine, policy-gated writes.

## Where the design comes from

ACCOUNT OVERVIEW §G, "Expense Accounts (Dynamic)", is the whole basis of the
schema:

> Super Admin ata-create categories: Umeme, Rent, Usafiri.
> Kila category: = Ledger yake

So a category is not a label on one shared expense account — it **owns** one.
That single sentence is why `expense_categories.chart_account_id` exists and is
NOT NULL, and it is what makes every expense breakdown in the reporting
specification (Branch Expense Report's "expense categories (rent, fuel, etc.)",
the consolidated P&L, Branch P&L feeding the commission engine) a grouped
ledger query rather than a scan of free-text descriptions.

## Screens and their legacy routes

| Legacy | This system | What it does |
|---|---|---|
| `/admin/expenses_name` | `/expenses/register` | The branch register: names a branch may spend against |
| `/admin/all_expenses` | `/expenses/requests` | Every branch request, with approve/reject |
| `/admin/aproved_expenses` | `/expenses/approved` | The approved ones, with a total |
| `/admin/hq_expenses_name` | `/hq/expenses/register` | The headquarters register |
| `/admin/all_hq_expenses` | `/hq/expenses/requests` | Every headquarters request |
| `/admin/aproved_hq_expenses` | `/hq/expenses/approved` | The approved ones |
| — | `/admin/expense-categories` | Both registers in one Settings screen |

## Schema

### `expense_categories`

`name`, `scope` (`branch` | `headquarters`), `chart_account_id`, `created_by`.
Soft-deleted, because a request already filed must keep naming its category and
last year's Branch P&L still has to resolve it.

**On the unique index.** One live name per register, enforced by a unique index
on `(name, scope, deleted_marker)` where `deleted_marker` is a generated column:

```sql
COALESCE(CAST(deleted_at AS CHAR), 'live')
```

Indexing `deleted_at` directly would enforce nothing. MySQL treats NULLs in a
unique index as distinct from one another, and every live row holds NULL there —
so the database would happily accept ten live "Rent" rows. Collapsing live rows
onto a shared literal makes them collide as intended, while a deleted row
carries its own timestamp and so releases the name for reuse. The column's
collation is `utf8mb4_unicode_ci`, which is case-insensitive, so the index and
`CreateExpenseCategoryAction`'s own `LOWER(name)` check agree on what counts as
a duplicate.

### `expense_requests`

| Column | Notes |
|---|---|
| `reference` | `EXP-0000001`, allocated from the highest in use |
| `expense_category_id` | Restrict-deleted |
| `scope` | Copied from the category, not taken from the caller |
| `branch_id` | The branch bearing the cost; head office for an HQ request |
| `requested_by` / `decided_by` / `decided_at` | |
| `amount`, `description` | |
| `comment` | The approver's note; stays editable after the decision |
| `status` | `pending` \| `approved` \| `rejected` |
| `journal_entry_id` | Set on approval; null while pending and forever if rejected |
| `requested_on` | When the cost was incurred, not when the row was created |

`scope` is denormalised deliberately: all four list screens filter on it first,
and a request stays in the register it was filed under even if the category is
later reclassified.

## Ledger posting

Every movement goes through `LedgerService::post()`, the only code path allowed
to write journal lines (§5).

| Event | Debit | Credit | Source type |
|---|---|---|---|
| Expense approved | The category's own `6200-n` account | Paying branch's teller cash | `expense` |
| Expense rejected | *(nothing)* | | |

The debit line carries `branch_id`, which is what makes the Branch Expense
Report and Branch P&L filtered queries rather than joins.

**The entry is dated `requested_on`, not the approval date.** A receipt filed
late would otherwise land in the wrong month's P&L, and the month a cost belongs
to is the month it was incurred.

**A pending request posts nothing.** Money leaves on approval, not on request —
the same rule branch-to-branch float follows, and the reason a queue of
unapproved requests never affects the trial balance.

### A note on the source document

ACCOUNT OVERVIEW's money-flow section (§4D) writes the expense entry as
`Dr Expense / Cr Income`. That cannot be right as double entry: crediting income
to record a cost would inflate revenue by exactly the amount spent, and the same
document's month-end rule — `Profit = Interest - Expenses` — depends on expenses
reducing the result rather than raising both sides of it. Read together with §G,
where every category owns a ledger, the intent is plainly `Dr Expense / Cr`
wherever the money left from. That is what is implemented, and it is what the
frontend's `types/expense.ts` independently specified ("Dr Expense · Cr
Cash/Bank").

## Dynamic account codes

`6000` and `6100` are taken by `SystemAccountCode` (Salary Expense, Commission
Expense), so dynamic expense accounts start at `6200` — where the frontend's own
chart puts them too. The suffix is an allocated sequence (`6200-1`, `6200-2`, …)
derived from the highest in use, the same way `LedgerService` derives the next
journal entry number.

It is **not** the category's id, unlike `AccountResolver::tellerCash`'s
`1500-{branch}`: `chart_account_id` is NOT NULL, so the account must be created
before the category that points at it, and a code derived from a row that does
not exist yet cannot be written.

The account is deliberately **not** branch-scoped. One "Rent" account carries
every branch's rent and the branch dimension on each line separates them — so
Branch Expense Report filters by branch while the consolidated P&L reads one
account rather than summing one per branch.

## Retiring a category

Soft-deletes the category and sets its ledger account to `inactive`.
`LedgerService` refuses to post to an inactive account, which is what makes the
frontend's promise — "New requests will no longer be able to file against this
name. Requests already filed keep it." — true at the posting layer and not only
in the picker.

The account is never deleted: it holds every shilling ever spent under that name.

Refused while a request is still **pending**, because a decision someone has yet
to make must not be made against a name that no longer exists. Approved and
rejected requests are history and pass through the soft delete unaffected.

## Mis-tagging

`POST /expense-requests` accepts an optional `scope`, which is never used to set
anything — it is a stated expectation, checked against the category's own scope
and refused (422) if they disagree. Each screen sends its own, so a branch screen
cannot quietly file a headquarters cost because someone passed the wrong category
id.

The reporting specification asks for an Expense Tagging Report with "mis-tagged
detection". This prevents the commonest way one gets created rather than leaving
the report to find it later.

## Permissions

| Ability | Permission |
|---|---|
| Read any expense screen | `treasury.view` |
| File a request | `treasury.view` |
| Create / rename / retire a category | `treasury.manage` **or** `admin.org_settings` |
| Approve / reject | `treasury.manage` + **not the requester** |
| Add or edit the decision comment | `treasury.manage` |
| Withdraw a pending request | the requester, or `treasury.manage` |

The pair is Treasury's rather than a new `expenses.*` one: the frontend already
gates every expense screen on `treasury.view` (`config/legacy-nav.ts`), and a new
permission would mean either one the UI never checks or a UI change to chase a
backend preference.

`admin.org_settings` additionally opens the category register because §G puts
creating categories in the administrator's hands — and each one mints a
chart-of-accounts row, however it is spelled on the screen.

**§14 separation of duties** applies to approval: the person who asked for the
money may not be the person who releases it. An expense is not exempt for being
small.

## Deleting

A pending request can be withdrawn — nothing has posted, so removing the row
removes nothing from the ledger. Once approved it has posted, and §2's no-delete
rule takes over: the endpoint returns `409 RESOURCE_IN_USE` and the only way back
is a ledger reversal.

## Seeded data

`ExpenseSeeder` builds both registers and seven requests **through the module's
own actions**, so each category gets its account minted the way a real one would
and each approval posts a real double entry. A seeded trial balance that includes
these expenses is therefore exercising the posting engine, not a fixture.

The four pending branch requests sum to **92,000**, which is the total the legacy
screen's footer prints — so the new screen can be compared against the old one
directly. Provenance of every value is recorded in the seeder's docblock.
