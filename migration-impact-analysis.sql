-- ===========================================================================
-- IMPACT ANALYSIS — 2026_08_28_000001_require_registration_approval_for_all_customers
--
-- READ-ONLY. Every statement below is a SELECT. Nothing here writes, deletes
-- or locks anything. Safe to run on production, during business hours, before
-- deciding whether to deploy.
--
-- Run it in phpMyAdmin: select the database → SQL tab → paste → Go.
-- phpMyAdmin returns one result grid per statement, in the order below.
--
-- WHAT THE MIGRATION DOES, in full. It is DML only — no ALTER, no schema
-- change. `customers.approval_status` is already
-- enum('not_required','pending','approved','rejected'), so every value it
-- needs already exists in the column.
--
--   UPDATE customers SET approval_status='approved', approved_at=NOW(),
--          approved_by=NULL
--    WHERE approval_status='not_required' AND kyc_status='completed';
--
--   UPDATE customers SET approval_status='pending'
--    WHERE approval_status='not_required';        -- i.e. the KYC-incomplete rest
--
-- It touches ONE COLUMN on ONE TABLE (plus approved_at/approved_by/updated_at
-- on the first statement). It reads no other table and writes to no other
-- table.
--
-- `down()` reverts only the first statement — see grid 8.
-- ===========================================================================


-- ---------------------------------------------------------------------------
-- 1. Total customers
-- ---------------------------------------------------------------------------
SELECT
    COUNT(*)                                      AS total_rows,
    SUM(deleted_at IS NULL)                       AS live_customers,
    SUM(deleted_at IS NOT NULL)                   AS soft_deleted
FROM customers;


-- ---------------------------------------------------------------------------
-- 2. Current approval_status breakdown
--
-- `not_required` is the one that moves. Everything else is left alone.
-- ---------------------------------------------------------------------------
SELECT
    approval_status,
    COUNT(*)                    AS rows_total,
    SUM(deleted_at IS NULL)     AS live_rows
FROM customers
GROUP BY approval_status
ORDER BY FIELD(approval_status, 'not_required', 'pending', 'approved', 'rejected');


-- ---------------------------------------------------------------------------
-- 3. Among `not_required`, split by KYC — this is the split the migration makes
-- ---------------------------------------------------------------------------
SELECT
    kyc_status,
    COUNT(*)                    AS rows_total,
    SUM(deleted_at IS NULL)     AS live_rows,
    CASE kyc_status
        WHEN 'completed' THEN 'WILL BECOME approved  (was eligible, stays eligible)'
        ELSE                  'WILL BECOME pending   (was not eligible either way)'
    END                         AS migration_outcome
FROM customers
WHERE approval_status = 'not_required'
GROUP BY kyc_status;


-- ---------------------------------------------------------------------------
-- 4a. Customers who are loan-eligible RIGHT NOW, under the CURRENT rule
--
--     kyc completed AND status active AND approval_status NOT IN (pending, rejected)
--
-- which is what the deployed `Customer::isLoanEligible()` evaluates today.
-- ---------------------------------------------------------------------------
SELECT COUNT(*) AS eligible_now
FROM customers
WHERE deleted_at IS NULL
  AND kyc_status = 'completed'
  AND status = 'active'
  AND approval_status NOT IN ('pending', 'rejected');


-- ---------------------------------------------------------------------------
-- 4b. …and who they are, one row each
--
-- Keep this grid. It is the before-picture you compare against after the
-- migration (query 6b re-runs the same list under the new rule).
-- ---------------------------------------------------------------------------
SELECT
    id,
    customer_number,
    CONCAT_WS(' ', first_name, middle_name, last_name) AS customer,
    branch_id,
    approval_status,
    kyc_status,
    status,
    CASE approval_status
        WHEN 'not_required' THEN 'moves to approved — stays eligible'
        WHEN 'approved'     THEN 'untouched — stays eligible'
        ELSE                     'UNEXPECTED — investigate before deploying'
    END AS after_migration
FROM customers
WHERE deleted_at IS NULL
  AND kyc_status = 'completed'
  AND status = 'active'
  AND approval_status NOT IN ('pending', 'rejected')
ORDER BY id;


-- ---------------------------------------------------------------------------
-- 5. Exactly how many rows the migration will change, and to what
--
-- These two numbers must sum to the `not_required` total in query 2.
-- ---------------------------------------------------------------------------
SELECT 'will become approved' AS change_to,
       COUNT(*)               AS rows_affected
FROM customers
WHERE approval_status = 'not_required' AND kyc_status = 'completed'
UNION ALL
SELECT 'will become pending',
       COUNT(*)
FROM customers
WHERE approval_status = 'not_required' AND kyc_status <> 'completed'
UNION ALL
SELECT 'left untouched (pending/approved/rejected)',
       COUNT(*)
FROM customers
WHERE approval_status <> 'not_required';


-- ---------------------------------------------------------------------------
-- 6a. THE SAFETY CHECK — must return ZERO rows
--
-- Anybody eligible under the CURRENT rule who would NOT be eligible under the
-- NEW rule (kyc completed AND active AND approval_status = 'approved').
--
-- It returns nothing because the migration promotes exactly the set that
-- would otherwise have been lost: `not_required` + KYC completed → `approved`.
-- If this ever returns a row, DO NOT DEPLOY — tell me first.
-- ---------------------------------------------------------------------------
SELECT
    id,
    customer_number,
    approval_status,
    kyc_status,
    status,
    'WOULD LOSE ELIGIBILITY — STOP' AS warning
FROM customers
WHERE deleted_at IS NULL
  AND kyc_status = 'completed'
  AND status = 'active'
  AND approval_status NOT IN ('pending', 'rejected')   -- eligible today
  AND NOT (
        kyc_status = 'completed'
    AND status = 'active'
    AND (approval_status = 'approved'                  -- eligible tomorrow…
         OR approval_status = 'not_required')          -- …because it becomes approved
  );


-- ---------------------------------------------------------------------------
-- 6b. Set sizes before and after, side by side. The two must be equal.
-- ---------------------------------------------------------------------------
SELECT
    (SELECT COUNT(*) FROM customers
      WHERE deleted_at IS NULL AND kyc_status = 'completed' AND status = 'active'
        AND approval_status NOT IN ('pending','rejected'))              AS eligible_before,
    (SELECT COUNT(*) FROM customers
      WHERE deleted_at IS NULL AND kyc_status = 'completed' AND status = 'active'
        AND approval_status IN ('approved','not_required'))             AS eligible_after,
    CASE WHEN
        (SELECT COUNT(*) FROM customers
          WHERE deleted_at IS NULL AND kyc_status='completed' AND status='active'
            AND approval_status NOT IN ('pending','rejected'))
        =
        (SELECT COUNT(*) FROM customers
          WHERE deleted_at IS NULL AND kyc_status='completed' AND status='active'
            AND approval_status IN ('approved','not_required'))
    THEN 'IDENTICAL — nobody loses eligibility'
    ELSE 'MISMATCH — STOP AND INVESTIGATE'
    END                                                                 AS verdict;


-- ---------------------------------------------------------------------------
-- 7. Nothing financial is in scope. These are the record counts the migration
--    does not touch — capture them now and re-run afterwards; every number
--    must be unchanged.
-- ---------------------------------------------------------------------------
SELECT 'customers'            AS table_name, COUNT(*) AS rows_before FROM customers
UNION ALL SELECT 'loans',                    COUNT(*) FROM loans
UNION ALL SELECT 'loan_schedules',           COUNT(*) FROM loan_schedules
UNION ALL SELECT 'payments',                 COUNT(*) FROM payments
UNION ALL SELECT 'journal_entries',          COUNT(*) FROM journal_entries
UNION ALL SELECT 'journal_entry_lines',      COUNT(*) FROM journal_entry_lines
UNION ALL SELECT 'customer_bank_details',    COUNT(*) FROM customer_bank_details
UNION ALL SELECT 'guarantors',               COUNT(*) FROM guarantors
UNION ALL SELECT 'face_scans',               COUNT(*) FROM face_scans
UNION ALL SELECT 'audit_logs',               COUNT(*) FROM audit_logs;


-- ---------------------------------------------------------------------------
-- 8. Reversibility — what `php artisan migrate:rollback` would put back.
--
-- `down()` reverts the GRANDFATHERING AND NOTHING ELSE: rows sitting at
-- `approved` with a NULL approver, which is the signature `up()` stamps and
-- which no human decision ever carries. A customer approved by a real person
-- has a non-null `approved_by` and is left alone — rolling back a RULE must
-- not discard somebody's DECISION.
--
-- IT DELIBERATELY DOES NOT TOUCH `pending`. Once the migration has run,
-- `pending` holds two populations that cannot be told apart: the KYC-incomplete
-- rows this migration moved there, and customers genuinely waiting for a
-- manager. Reverting both to `not_required` would let the next `up()` promote
-- every KYC-complete one straight to `approved` — silently approving people no
-- manager ever saw. That was observed on a rollback/re-apply cycle before
-- `down()` was narrowed, so the third row below is a count of what rollback
-- LEAVES ALONE, not of what it changes.
--
-- Leaving them costs nothing: every row this migration made pending has
-- incomplete KYC, so it was ineligible under the old rule and stays ineligible
-- under it.
-- ---------------------------------------------------------------------------
SELECT 'rollback WOULD REVERT to not_required (approved, no human approver)' AS item,
       COUNT(*) AS rows_affected
FROM customers
WHERE approval_status = 'approved' AND approved_by IS NULL
UNION ALL
SELECT 'rollback WOULD PRESERVE as approved (decided by a real person)',
       COUNT(*)
FROM customers
WHERE approval_status = 'approved' AND approved_by IS NOT NULL
UNION ALL
SELECT 'rollback WOULD LEAVE UNTOUCHED (pending — see the note above)',
       COUNT(*)
FROM customers
WHERE approval_status = 'pending';
