# Loan Approval Workflow

The chain the client specified:

```
Loan Officer → Branch Manager → Zone Manager → Head Office Credit → Disbursement
```

with **Approve**, **Reject**, **Return for Modification** and **Hold** available
at every stage, and every action audited.

---

## 1. The pieces

| Piece | File | Responsibility |
| --- | --- | --- |
| `loan_approval_stages` | migration | The chain, as rows. Order, permission, active/inactive. |
| `loan_approval_decisions` | migration | Append-only record of who decided what, where, and why. |
| `LoanApprovalWorkflow` | `Domain/Loans/Services/` | Reads the chain and answers. Writes nothing. |
| `RecordApprovalDecisionAction` | `Domain/Loans/Actions/` | The only place a decision is taken. |
| `LoanApprovalController` | `Http/Controllers/Loans/` | One decision endpoint, one read endpoint. |

The Loan Officer is **not** a stage — they raise the application, and §14
forbids them from also approving it. Disbursement is not a stage either: it is
Finance executing a decision already taken, which is why the chain ends by
handing the loan to `pending_finance`.

---

## 2. Why the chain is a table

A four-step chain written into a `match` looks reasonable until the business
wants zone sign-off skipped below 500,000, or a region added, or the order
swapped. Each of those then costs a deploy.

Stages are rows: ordered by `sequence` (gapped by ten so one can be inserted
without renumbering) and switchable by `is_active`. Deactivating the zone tier
reroutes the next approval with no code change — proved by a test.

**The honest limit.** Each stage names the `loans.status` a loan waits in, and
those statuses are a PHP enum the frontend mirrors. Reordering, deactivating and
re-permissioning stages are pure data. Introducing a genuinely *new kind* of
stage still needs a status case in both repositories. That is the one code
touchpoint, and pretending otherwise would be worse than saying so.

---

## 3. The seeded chain

| Seq | Code | Status | Permission | Notes |
| --- | --- | --- | --- | --- |
| 10 | `BRANCH_MANAGER` | `pending_manager_approval` | `loans.approve` | Schedule is generated when this clears |
| 20 | `ZONE_MANAGER` | `pending_zone_approval` | `loans.zone_approve` | New tier |
| 30 | `HEAD_OFFICE_CREDIT` | `pending_credit_review` | `loans.credit_review` | `requires_mandate_before` |

`loans.zone_approve` is a grant of its own, not a second use of `loans.approve`.
A Branch Manager holding both could walk their own branch's loan through two
consecutive tiers, which is the exact escalation a chain exists to prevent.

`loans.hold` is separate again: an approver who may clear a loan is not
automatically someone who may park it indefinitely, and the two are separately
auditable.

---

## 4. The decisions

| Decision | Effect | Reason required |
| --- | --- | --- |
| `approved` | Clears the stage; moves to the next one, or to `pending_finance` | no |
| `rejected` | Terminal. The application is over | **yes** |
| `returned_for_modification` | Back to the officer. **Schedule discarded** | **yes** |
| `held` | Paused in place; `hold_resume_status` remembers where | **yes** |
| `released` | Back to the exact stage that paused it | no |

Plus `resubmit`, which is the officer's side of a return and re-enters the chain
from the **first** stage — something has changed, so every approver who already
cleared it cleared a different application.

A release needs no reason because the hold's reason is already on the record;
demanding a second explanation for undoing it would be paperwork rather than
accountability.

### Why one action and not four

The parts that must never differ are the parts easy to get subtly wrong twice:
the permission check, the separation-of-duties refusal, the transaction
boundary, the status-history row, the decision record and the audit entry. Four
actions would be four chances for a hold to skip the self-approval check.

### Why a return discards the schedule

The officer is being asked to change something. A plan priced on the terms the
application used to have, surviving the round trip, is how a loan ends up
disbursed against a schedule nobody approved.

---

## 5. Authorization

Two refusals, both server-side, neither of them UI hiding:

1. The actor holds the **stage's configured permission**.
2. The actor **did not raise the application** (§14).

A permission rather than a role is named on the stage, because roles →
permissions is already an administrator-managed matrix; naming a role would put
a second, competing authority on "who may approve" into the schema.

`GET /loans/{loan}/approval` reports `availableDecisions` from the *same* rule
that would refuse the write. So the UI never offers a button the server would
reject, and never hides one from somebody entitled to press it.

---

## 6. The audit trail

`loan_status_history` already recorded every transition, but a transition is not
a decision. A hold and a return both leave `pending_zone_approval`; an approval
at stage two and at stage three look alike from the status alone; and neither
records *which stage* an approver was acting for.

`loan_approval_decisions` is append-only and denormalises the stage code and
name, so a trail read years later still says "Zone Manager" even if that stage
has since been renamed or retired.

Each decision also writes its own audit action — `LOAN_APPROVAL_STAGE_CLEARED`,
`LOAN_RETURNED_FOR_MODIFICATION`, `LOAN_HELD`, `LOAN_RELEASED_FROM_HOLD`,
`LOAN_RESUBMITTED` — rather than one "loan decided" with a payload to grep.
"Who held this loan for three weeks" has to be a query.

---

## 7. The mandate branch

§10's conditional branch is data, not an `if` on a status name: the stage that
must not begin until the bank e-mandate is live carries
`requires_mandate_before`. Approving the stage *before* it opens the OTP flow;
`VerifyMandateAction` then asks the workflow where to land rather than naming a
status, so deactivating the credit stage cannot strand a mandate-bearing loan.

A mandate is opened once. A loan returned, amended and re-approved is not sent
through the OTP flow again for a mandate the bank has already granted.

---

## 8. Endpoints

```
GET  /api/v1/loans/{loan}/approval             where it is, and what you may do
POST /api/v1/loans/{loan}/approval/decide      { decision, reason? }
POST /api/v1/loans/{loan}/approval/resubmit    { note? }
POST /api/v1/loans/{loan}/approve-manager      stage one, kept for compatibility
```

`approve-manager` is retained because the existing route, frontend action and
tests speak to it. It delegates to the same implementation and adds one guard of
its own: it decides the manager stage and nothing else.

---

## 9. Verification

`tests/Feature/Loans/LoanApprovalWorkflowTest.php` — 24 tests, driven through
the real endpoints as the real roles. A test that called the action directly
would prove the arithmetic of the chain and none of the thing the chain exists
for: that a different person signs off at each tier, and that the system refuses
when one does not.
