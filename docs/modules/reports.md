# Module: Reports

Sidebar → **Report**. Thirty-eight reports, every one a read-model recomputed
from the operational tables and the ledger on each call.

Source of truth: `🧠 OVERVIEW ALL REPORT.docx`, plus spec §15.6. Section numbers
below are the reports document's.

## What this module added

Twenty-eight reports already existed. Ten did not, and each is a numbered
section of the reports document that §15.6's own list omits:

| § | Report | Slug |
|---|---|---|
| 3C | Branch Expense | `branch-expense` |
| 4B | HQ Expense | `hq-expense` |
| 4C | HQ Allocation (2%) | `hq-allocation` |
| 6B | Profit Adjustment | `profit-adjustment` |
| 6C | Commission Eligibility | `commission-eligibility` |
| 7B | Balance Sheet | `balance-sheet` |
| 7C | Cash Position | `cash-position` |
| 9C | Daily Position | `daily-position` |
| 10B | Growth | `growth` |
| 10C | Risk | `risk` |

None needed a schema change. Every one reads tables the operational modules
already write, which is what a report is for.

`NewReportsTest` asserts the mapping from the document's own names to slugs, so
deleting one is a test failure rather than a quietly shorter catalogue.

### Decisions worth stating

**Balance Sheet computes retained earnings.** There is no retained-earnings
account, because nothing closes the books at year end. Income less expense *is*
the retained earnings to date, and without it the sheet would not balance — a
reader would take that for a ledger bug rather than a missing account. Control
accounts get their own section for the same reason: they carry real balances,
and dropping them would break the equation.

**Cash Position separates cash from receivables.** The loan book is a real asset
and is not money that can be spent this afternoon. That distinction is the
report; folding receivables into "available cash" would be the exact error it
exists to prevent.

**Daily Position opens at the prior balance, not zero.** A running balance that
started at zero would close at the period's movement rather than the position —
the same figure as Net, and wrong wherever it mattered. It ties to Cash
Position's Available Cash for the same date.

**Risk compares against the company, not a threshold.** The documents give no
numbers, and a hard-coded "PAR above 5%" would be this system asserting a risk
appetite nobody stated. A branch is flagged at 1.5× the company figure — a
statement the data supports — and every underlying number is on the row for a
reader who disagrees with the multiplier.

**HQ Expense refuses a branch filter.** "HQ expenses at Kakonko" is not a
question with an answer, so the filter is dropped rather than silently honoured.

### One claim corrected during the work

The first draft of Branch Expense said its total tied to Branch P&L's Expense
column. It does not, and the test caught it: that column is *every*
expense-type account for the branch — salary, commission and bank charges
included — and none of those is raised as an expense request. The reconciliation
note now says what is true: the total ties to the expense-category chart
accounts, and is a **subset** of the P&L column.

## Search, sort and pagination

`ReportQuery` and `ReportPresenter`, deliberately separate from `ReportFilters`.

The four filters — branch, period, from, to — decide **what the figures are**,
and §15.6 echoes them in `filters_applied`. Search, sort and page decide only
which rows are shown and in what order. Folding them together would tell a
reader that sorting had changed a total.

`meta.query` echoes the presentation separately, and `meta.report.sortable`
lists the column keys the API will order by, so a client need not guess.

### Why presentation rather than SQL

A report is a read-model. Its rows mostly do not correspond to database rows at
all — a branch P&L row is eleven aggregates across four tables, an age-analysis
row is a bucket — so there is no `ORDER BY` that could sort them without the
report computing them first. The sets are bounded by construction, and the two
that are not already carry a date window.

### Three rules the presenter follows

- **Pagination never touches totals.** A total that summed only the visible page
  would be a different number on page two.
- **Search does.** A search narrows what the report is *about*, so totals are
  recomputed over what matched. Text total cells like "11 payslips" are replaced
  with the matched count rather than carried over, because a stale label is a
  lie a reader cannot detect.
- **Money sorts with `bccomp`, never as a float or a string.** `'1000000.10'`
  sorts below `'999999.99'` as a string, and casting to float puts a rounding
  error into an ordering.

Pagination is **opt-in**: absent `per_page`, every row is returned. A trial
balance cut off at row fifty is not a shorter report, it is a wrong one.

A sort by a column that does not exist is reported in `meta.sortIgnored` rather
than ignored — the caller believes the order means something, and silently
serving an unsorted list is how a reader concludes the data is wrong.

## Exports

`GET /reports/{slug}/export?format=csv|xlsx|pdf`, and the frontend's
`/reports/{slug}/download` route handler in front of it so the API token stays
server-side.

The export carries the caller's filters, search and sort, and **not** their
paging — an export that did not match the screen it was taken from is the one
thing an export must never be, and a page of a spreadsheet is not an export.

### Why the formats are written by hand

PhpSpreadsheet and Dompdf are the obvious choices and both were tried. Neither
could be installed: this environment reaches Packagist's metadata but not
GitHub, where the archives live. `composer require` resolves and then fails on
the download.

So both are written directly. An `.xlsx` is a ZIP of five XML parts and PHP
ships `ZipArchive`; a PDF of a table is a handful of objects and one content
stream. What is given up is styling depth and font embedding, neither of which a
report export needs. `ReportExporter`'s three `render*` methods are the entire
surface to replace if the libraries become installable.

Details that matter and are easy to get wrong:

- **The CSV carries a UTF-8 BOM.** Without it Excel on Windows reads the file as
  the system codepage and mangles every non-ASCII character — in a Tanzanian
  customer list, a good number of names.
- **XLSX writes a number only when the column says so *and* the value parses.**
  Reports use an em dash for "nothing here", and writing that into a numeric
  cell makes Excel show `#VALUE!` for a row that is perfectly fine.
- **PDF is landscape A4 in Helvetica**, one of the fourteen fonts every reader
  must have, so nothing is embedded. Text is transliterated to WinAnsi rather
  than emitted as bytes the reader would render as a different glyph.

`ReportQueryTest` generates all three formats for all thirty-eight reports.

## Permissions and branch scope

Unchanged, and both apply to the new reports: a single `reports.view` grant, and
§13's scope forced by the controller — a user without `branches.view_all` is
pinned to their own branch regardless of what the query string asks for. A
report must not be a way around the scoping every other endpoint enforces.

## Query budgets

`QueryBudgetTest` computes every report in the registry and fails if one exceeds
its budget. The budgets are loose on purpose — a number tightened to the exact
current count would fail on every harmless refactor and be silenced rather than
investigated — but they catch the shape changing from O(branches) to O(rows).

Measured on the seeded book: most reports issue two to eight queries; the
per-branch ones (Branch P&L, Branch Ranking, Risk) are O(branches) because each
branch needs its own trial balance; Executive Summary composes nine reports.

## The seeded book

`ExpenseSeeder` gained five approved head office expenses across three months,
because §4B asks for a month-on-month comparison and the book had no approved HQ
expense at all — the report was correct and empty, which demonstrates nothing: a
reader cannot tell an empty report from a broken one.

Approved requests are also now **backdated to the day they were raised**.
`DecideExpenseRequestAction` stamps `decided_at` with the moment of the
decision, which is right at runtime and useless in a seeder: every seeded
expense would otherwise land in the month the database happened to be built.
Only that column moves; the journal entry keeps its own date and no money
changes.

## Frontend

The generic `/reports/{slug}` screen gained a search box, sortable headers, a
page-size control with paging, and CSV/XLSX/PDF buttons.

Everything lives in the URL. A report someone is looking at — filtered,
searched, sorted, on page three — is a link they can send, a refresh does not
lose it, and the export anchors carry the same query string the page was
rendered from, so the file and the screen cannot disagree.

A header is only clickable when `meta.report.sortable` contains its key: one
that looked clickable and did nothing would be worse than a plain one.

The thirteen legacy report screens were already on the real API and needed no
change.
