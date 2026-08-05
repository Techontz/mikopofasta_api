# Enterprise Organizational Structure

```
SUPER ADMIN  →  HEAD OFFICE  →  ZONES  →  BRANCHES
```

---

## 1. What was already there, and what was added

Most of this structure existed in the data before this phase: `branches.zone_id`,
`branches.parent_branch_id`, `branches.is_head_office`, `users.branch_id /
zone_id / region_id`, and `BranchScope`'s rules about who sees how far.

What did not exist was a **name for the answer**. Every screen that wanted to
behave differently for a Zone Manager than for a Branch Manager re-derived it
from role names. `OrganizationTier` and `OrganizationHierarchy` stop that.

| Added | Why |
| --- | --- |
| `OrganizationTier` | One vocabulary for where somebody sits |
| `OrganizationHierarchy` | Computes the tier and the reporting line, once |
| 5 operational roles | Accountant, Cashier, Recovery Officer, Customer Care, Head Office Manager |
| `system` role + `system` status | The automation's identity — Decision 4 |
| `/organization/structure`, `/organization/me` | The console's data, and everyone's own position |

---

## 2. Tier is read from the POSTING, not from the role

The client's list names "Head Office Loan Officers", "Head Office Tellers",
"Head Office Accountant" — and separately "Loan Officers", "Tellers",
"Accountant" at each branch. **Those are the same job done at different
offices, not different jobs.**

A branch-scoped system already says that: a Head Office Teller is a `teller`
whose `branch_id` is the branch flagged `is_head_office`.

Minting `ho_teller` beside `teller` would have doubled the role list, doubled
the permission matrix, and recorded the office **twice** — in the role and in
the posting — free to contradict each other the day somebody transfers.

Super Admin and System are the two exceptions, and both are genuinely roles
rather than places: one governs from no office, the other is not a person.

### The seam that keeps it honest

`OrganizationHierarchy::tierFor()` and `BranchScope::visibleBranchIds()` are two
services reading the same facts. A user told they are Head Office while the API
returns one branch's data would be worse than no hierarchy at all — so the tests
assert both together, per tier.

---

## 3. The roles

| Role | Holds | Deliberately does NOT hold |
| --- | --- | --- |
| Head Office Manager | approve, hold, settle early, view everything, HR view | **disburse**, **period close**, **cross-branch review** |
| Accountant | ledger, reconcile, period close, reversal request | approve, disburse, cash entry |
| Cashier | cash entry, treasury view | **reconcile** |
| Recovery Officer | recover, cash entry, reports | **write-off** |
| Customer Care | customers view/manage, loans view, repayments view | everything that decides |
| System | nothing at all | everything |

Each "does not hold" is a control:

- The **Cashier** holds the cash and the **Accountant** agrees it reached the
  bank. The same person doing both is the oldest control failure there is.
- The **Recovery Officer** chases a debt; **Finance** can forgive one. An
  officer who could do both could make a shortfall disappear.
- The **Head Office Manager** is senior, and seniority is not a reason to
  collapse separation of duties. In particular they do **not** get
  `loans.review_cross_branch` — §13/§14 keep that an explicit per-user grant,
  and seeing every branch is not authority to act on every branch.

---

## 4. Zones

Zone Managers supervise several branches: they approve (`loans.zone_approve`),
hold, view reports and monitor performance. **No teller functions** — asserted
as a test, not left to the matrix being read carefully.

`BranchScope` already narrows a zone-pinned user to their zone's branches, which
is why a Zone Manager is `Zone` tier even though they see more than one branch.

A branch belonging to **no** zone is surfaced by the console rather than hidden:
it falls outside every Zone Manager's scope, so nobody supervises it, and that is
almost always an oversight.

---

## 5. The Super Admin console

`/admin/structure` — the hierarchy, the headcount per tier and per role, the
unsupervised branches, and links into the modules that own each setting.

**It is a surface, not a second system.** Institution, capital, shareholders,
reserve settings, configuration, branches, zones, products, roles, permissions
and master data each already have a module. Rebuilding them behind a second set
of screens would be two places to change one thing, free to disagree — the
duplication the standing instruction forbids.

It sits **above** the legacy Settings grid rather than inside it. That grid
reproduces the old system's menu verbatim — entries, order, spellings — because
operators have navigated it for years.

### Super Admin and "no operational loan processing"

The console offers no origination surface. The permission set is a separate
question and is **left as it was**: `super_admin` holds everything and
`isEditable()` is false for it, which is a deliberate break-glass property. See
the decision register — whether to actually revoke operational grants from Super
Admin is a business decision with a real cost, and is flagged rather than taken.

---

## 6. The System account — client Decision 4

Non-login, permissionless, posted to no office. Used by nightly jobs, interest
accrual, advance consumption, reserve transfers and every automatic posting.

Four independent barriers, so removing any one does not open it:

1. `status = system`, and `LoginAction` refuses anything whose
   `canAuthenticate()` is false.
2. A fresh 64-byte random password that is never recorded.
3. A role with **zero** permissions.
4. No email, so the password-reset broker cannot reach it.

`SystemActor` **refuses** rather than falling back. The earlier version resolved
"the lowest-id Super Admin, or failing that any user" — exactly what the client
ruled out. A missing System account is a deployment error and should look like
one immediately, not silently produce months of entries attributed to a real
person who did not make them.

---

## 7. Verification

`tests/Feature/Organization/EnterpriseStructureTest.php` — 27 tests covering
tiers, the tier/scope agreement, reporting lines, both endpoints, the System
account's four barriers, and each separation-of-duties control above.
