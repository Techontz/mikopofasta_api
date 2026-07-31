# Module: Loan Charges & Reserve

Settings → **Loan Fee**, **Penalty**, **Reserve Setting**.

The three Settings entries the legacy system serves and this one did not. Each is
configuration: what a borrower is charged on top of interest, what a late
borrower is charged, and how much of the portfolio is held back.

## Workflow, from the legacy system

Taken from the screens at `mikopofasta.co.tz/admin/*`, which define the workflow.

| Legacy screen | What it does |
|---|---|
| `/admin/loan_fee` | Picks a fee strategy, then lists every loan category with its level, interest, fee type, fee and insurance. Each row editable. |
| `/admin/penart_setting` | One calculation type + amount, saved by `Update`; the saved settings listed below with a delete action. |
| `/admin/reserve_setting` | A single `Reserve Percentage`, saved by `Update`. |

Legacy vocabulary maps onto this system as:

- *Loan Category* → `loan_products`
- *Loan level* → `loan_products.min_amount`–`max_amount`
- *Loan Interest* → `loan_products.interest_rate`

## Schema

Three tables. Nothing existing is altered.

### `loan_fees`
One row per loan product. Holds the arrangement fee and the insurance premium.

| Column | Type | Notes |
|---|---|---|
| `loan_product_id` | FK, unique | One fee configuration per product |
| `fee_type` | enum | `money_value` \| `percentage_value` |
| `fee_amount` | decimal(18,2) | TZS when `money_value`, percent when `percentage_value` |
| `insurance_amount` | decimal(18,2) | Flat TZS premium |

`fee_type` reuses the legacy's own two options (`MONEY VALUE`, `PERCENTAGE VALUE`).

### `penalty_settings`
The organisation-wide penalty default.

| Column | Type | Notes |
|---|---|---|
| `calculation_type` | enum | `percentage_value` \| `money_value` |
| `amount` | decimal(18,3) | Percent or flat TZS, per `calculation_type` |

### `reserve_settings`
Singleton — one row, enforced by the action rather than by schema, matching how
`company_profiles` is handled.

| Column | Type | Notes |
|---|---|---|
| `percentage` | decimal(6,3) | 0–100 |

## The one decision that carries business consequence

**The global Penalty setting does not change how any penalty is calculated.**

This system already prices penalties per loan product — `penalty_type`,
`penalty_rate`, `penalty_grace_days`, `penalty_cap_amount` — and snapshots the
rate onto each loan at origination (`loans.penalty_rate_snapshot`). The overdue
job reads that snapshot.

A global setting that overrode any of it would silently re-price live loans.
So it does not override. It is the **default offered when a new loan product is
created** and nothing more. Existing products, existing loans and every figure
the overdue job produces are untouched.

~~The same boundary applies to `loan_fees`: the configuration is stored and
served, but nothing consumes it yet.~~ **Superseded.** `loan_fees` is now
charged at disbursement — see docs/modules/penalties-and-fees.md. All four steps
this section listed are done: the fee is snapshotted onto the loan at
application (not at disbursement, so a mid-term Settings edit cannot re-price an
agreed loan), `SettleDisbursementAction` adds the fee leg, `loans.fee_charged`
records what was withheld, and the trial-balance tests needed no re-baselining
because they assert balance rather than fixed figures.

`reserve_settings.percentage` is likewise stored and served; the reserve figure
the dashboard shows still reads `3000 Reserve Account` from the ledger.

## Permissions

All three sit behind `admin.org_settings`, the same grant the rest of Settings
uses. Reads are open to any authenticated user, because the loan product screens
and the future disbursement path both need the values — the same read-open /
write-gated split `ZonePolicy` and `BranchPolicy` use.

## API

| Method | Route | Permission |
|---|---|---|
| `GET` | `/api/v1/loan-fees` | authenticated |
| `PUT` | `/api/v1/loan-fees/{product}` | `admin.org_settings` |
| `DELETE` | `/api/v1/loan-fees/{product}` | `admin.org_settings` |
| `GET` | `/api/v1/penalty-settings` | authenticated |
| `POST` | `/api/v1/penalty-settings` | `admin.org_settings` |
| `DELETE` | `/api/v1/penalty-settings/{penaltySetting}` | `admin.org_settings` |
| `GET` | `/api/v1/reserve-setting` | authenticated |
| `PUT` | `/api/v1/reserve-setting` | `admin.org_settings` |

Envelope, error codes and camelCase field naming follow the existing contract.

## Frontend

Routes are new, the navigation is not: the three entries already exist in the
Settings accordion in the order the legacy menu lists them, and this module only
gives them somewhere to go.

- `/admin/loan-fees`
- `/admin/penalty`
- `/admin/reserve-setting`

Presentation uses the Settings component kit (`components/settings`).
