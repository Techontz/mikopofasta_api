# Module: Administration & System Configuration

Settings → **Interest Formula**, **Repayment Schedules**, **Notification
Templates**, **Audit Logs**.

Four screens with one shape: reference data that is open to read and gated to
change. What differs between them is how much may be changed at all, and each
answer is a consequence of what the data actually drives.

## Interest formulas — name and description only

There is no create route and no delete route, on either side of the system.

`code` is not a label. It is a branch in the interest engine: SIMPLE, FLAT and
REDUCING are the three `LoanScheduleGenerator` implements. The frontend reached
the same conclusion independently and says so in its own comment —

> `code` is fixed because lib/domain/loan-schedule.ts branches on
> SIMPLE/FLAT/REDUCING by code; it isn't a free-text CRUD field.

A fourth row would be a formula nothing knows how to compute. A product could be
configured with it and every loan priced from that product would fail at
origination — not with a validation error, but at the moment someone tries to
borrow. Deleting one would orphan every product using it.

So the screen edits what a formula is **called** and how it is **explained**,
which is genuinely useful — "REDUCING" tells a new officer nothing — and changes
no arithmetic. Adding a formula is a code change, and should be.

Each row carries `productCount`, so the weight of a rename is visible beside it.

## Repayment schedules — open, with two guards

`frequency_days` is a number the schedule generator divides by, not a branch it
switches on, so a fortnightly or quarterly cadence is genuinely a configuration
change. Full create, update and delete.

Two things are refused:

| Refused | Why |
|---|---|
| Changing `frequency_days` once loans run on it | It generated every installment date on those loans. Change it and the dates say fortnightly while the row says monthly — and nothing regenerates them. |
| Retiring a schedule with loans, or one a product offers | The loan's own cadence would no longer be explicable; the product would offer a schedule that no longer exists. |

The name and code stay editable throughout. They are labels, and correcting one
changes no arithmetic — which is the whole distinction this module keeps making.

Deletion is a soft delete, so a historical loan can still name what it ran on.

Codes are stored upper-cased and matched case-insensitively: `weekly` and
`WEEKLY` differing only in case would pass a unique index while reading as the
same thing to everyone looking at them.

## Notification templates

The one genuinely new table in this module. The frontend's type file explains
why it exists:

> Not in the original 54-table backend schema — the docs describe SMS/email
> being sent on specific events but not a template management table. A small,
> clearly-scoped addition so "Notification Templates" is a real, editable entity
> rather than hardcoded message strings.

That reasoning is adopted rather than re-litigated. The business documents *do*
specify the messages — REPAYMENT OVERVIEW §1 Step 5 gives one verbatim,
*"Tumepokea malipo yako ya XXX"* — and a message the business has written down
belongs in a row somebody can edit, not in a string literal a developer has to
be asked to change.

### Three rules, all enforced server-side

**Placeholders must be ones the event can supply.** Which they are depends on
`trigger_event`, so it cannot be a static validation rule. An unknown
placeholder is not a nicety: it reaches the customer as the literal text
`{{amount}}`, and the only person who can prevent that is the one writing the
message. `NotificationTriggerEvent::placeholders()` is the single definition,
and the API sends it to the editor in `meta.triggerEvents` so the form offers
the server's answer rather than a second copy that could drift.

**One active template per (event, channel).** Two active SMS templates for
`payment_received` would leave the sender picking one arbitrarily — the customer
gets whichever row came back first. Enforced by the action, so the message names
which template is already live, and by a partial unique index, so a race cannot
slip past.

**SMS carries no subject.** Supplying one is refused rather than quietly
dropped.

### The partial unique index

```sql
uniqueness_marker AS (CASE WHEN deleted_at IS NULL AND active = 1 THEN 'live' ELSE NULL END)
UNIQUE (trigger_event, channel, uniqueness_marker)
```

This uses MySQL's NULL-distinctness **deliberately**, and it is the inverse of
what `expense_categories` needed. There the goal was to constrain live rows, and
a NULL `deleted_at` made them all look distinct — so the marker had to collapse
them onto a shared literal. Here the goal is to constrain live rows and leave
every other row unconstrained, so the marker is `'live'` for exactly those and
NULL for the rest, and NULLs never collide.

Inactive rows therefore fall outside it: any number of drafts may sit beside the
one in use, and standing the live one down promotes a draft with no juggling.

### Seeded wording

Swahili, because the customers are Tanzanian and the one message the documents
write out verbatim is Swahili. `payment_received` reproduces it exactly, with
`{{amount_paid}}` where the document writes XXX. The others follow its register.

All eight are SMS. That is the channel the documents actually describe —
`POST /notifications/sms` is the call REPAYMENT OVERVIEW makes — and the customer
base reached by phone rather than email. Email templates are left for whoever
wants them: seeding eight empty ones would put rows on the screen nobody wrote
and nothing sends.

**They are meant to be edited.** That is the point of the screen. A seeded row
is a working default so no event is silent, not a decision about what the
business wants to say.

## Audit trail

Read-only in the strongest sense the router can express: `GET /audit-logs` is
the only route, and there is no show, update or destroy for a single entry
anywhere. §2 makes the trail append-only, and an endpoint that could rewrite a
row would defeat the only thing it is for. A test asserts the absence.

Filters: action, actor, record type, record id, date range, and a search across
the action, the record type and the actor's name. `auditable_type` accepts
either the fully-qualified class the column stores or the short name the screen
shows — someone filtering from what they can see should not have to know the
namespace.

`meta.actions` lists the actions **actually present**, not the enum. §2.1 calls
for an extensible audit vocabulary and the column is a VARCHAR, so a filter
built from the enum would go stale the moment a later phase wrote an action the
enum had not caught up with.

The resource emits `auditableType` (the class) *and* `auditableLabel` (the short
name). The long one is what makes an entry traceable back to the record; the
short one is what a person reads. Replacing rather than supplementing would make
the audit trail less precise than the thing it audits.

### Who may read it, and how much

| Read | Requires |
|---|---|
| The whole trail | `audit.view` **or** `admin.org_settings` |
| One record's history | Whatever that record's own policy requires, plus §13 branch scope |

The whole trail records every module's activity — salary figures, identity
changes, every approval — so it reveals more than any single screen it
summarises. `audit.view` exists precisely so that reading it can be granted to
an auditor without granting the ability to change settings, and withheld from an
administrator who can. The Auditor role holds it; Admin reaches it through
`admin.org_settings`, matching where the frontend's nav files it.

A trail **pinned to one record** is a different question. The audit panel on a
customer's profile and a loan's detail page shows that record's own history —
who approved it, when it was disbursed — which is what the rest of the page
already says. Requiring the global grant would hide a loan's history from the
officer working the loan, which is neither what §2 protects nor what the screens
are for. So a pinned read is authorised against the record's own policy.

Two things make that safe:

- **The types are enumerated** (`Customer`, `Loan`), not resolved from the
  string. Letting a caller name any class would turn this into a way to probe
  for models with permissive policies.
- **Branch scope is applied explicitly.** `LoanPolicy::view` deliberately
  answers only the permission question — its own docblock says §13 belongs to
  `BranchScopeGuard`, because a scope failure must surface as
  `BRANCH_SCOPE_VIOLATION` and be audited, which a yes/no policy cannot do.
  Without the guard, `loans.view` alone would have let an officer read the
  history of a loan at a branch they cannot see the loan itself at. That gap
  existed in the first draft of this module and is covered by a test.

## Permissions

| Ability | Permission |
|---|---|
| Read formulas, schedules, templates | none — authenticated |
| Change any of the three | `admin.org_settings` |
| Read the whole audit trail | `audit.view` or `admin.org_settings` |
| Read one record's history | that record's own policy, plus branch scope |

Reads of the reference data are deliberately ungated, matching `ZonePolicy`,
`BranchPolicy` and `LoanChargePolicy`. A loan officer's product picker needs the
schedule names, and a screen that could not name the formula a product uses
would be less useful for no security gain — none of it is sensitive, and all of
it is already implied by the product list.

## Endpoints

| Method | Path |
|---|---|
| GET | `/interest-formulas` *(with `LoanConfigurationController`, the application form's lookup)* |
| PUT | `/interest-formulas/{formula}` |
| GET | `/repayment-schedules` *(same)* |
| POST / PUT / DELETE | `/repayment-schedules[/{schedule}]` |
| GET / POST / PUT / DELETE | `/notification-templates[/{template}]` |
| GET | `/audit-logs` |

The two index routes stay with the loan configuration they are lookups for. One
implementation per URL — the settings screens read the same endpoint the
application form does, and get the usage counts as extra keys.

## Frontend

| Screen | Reads | Writes |
|---|---|---|
| `/admin/interest-formulas` | `getInterestFormulas` | `updateInterestFormula` |
| `/admin/repayment-schedules` | `getRepaymentSchedules` | create / update / delete |
| `/admin/notification-templates` | `getNotificationTemplates` | create / update / delete / toggle |
| `/admin/audit-logs` | `getAuditLogs` | — |

Three fixture behaviours were replaced by the server's answer rather than
reproduced:

- **The schedules screen guarded deletion against `MOCK_LOANS`.** Only the
  server can see every loan. The counts now come down with each row, so the
  delete button is disabled with the reason on it rather than failing on click.
- **The template form kept its own list of events and placeholders.** It now
  offers the server's, because the server is what validates against them.
- **The audit panels on customer and loan detail pages read `MOCK_AUDIT_LOGS`.**
  They read the real trail, pinned to the record.

One integration bug the wiring surfaced: switching a template's channel from
email to SMS hides the subject field but leaves the typed value in form state,
and the API refuses a subject on an SMS. The payload now drops it, so a template
that looks fine on screen is not rejected on save.

`lib/mock-data/{audit-logs,notification-templates,repayment-schedules}.ts` were
deleted once nothing referenced them.

## Factories

`NotificationTemplateFactory` only. Interest formulas are a fixed set of three
the engine branches on, and repayment schedules are seeded reference data whose
codes products and loans point at — a random one of either is a row nothing in
the system knows what to do with, so both are built through their seeders or
their endpoints.

`forEvent()` sets the event and the body together on purpose: an event carries
its own allowed placeholders, so moving a body written for `payment_received`
onto `payment_overdue` produces a template the save endpoint rejects — correct
behaviour, and a confusing way for a fixture to fail.
