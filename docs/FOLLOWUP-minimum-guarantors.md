# Follow-up: two sources of truth for the guarantor minimum

**Status:** documented, not implemented. Raised during the 2026-08-30 registration work
and deliberately left out of it — it changes who may borrow, and that deserves its own
controlled pass with its own before/after count.

## The conflict

Two places answer "how many guarantors does this customer need?", and they can disagree.

**Source A — a constant in the eligibility checker**

`app/Domain/Loans/Services/LoanEligibilityChecker.php:41`

```php
public const int MINIMUM_GUARANTORS = 1;
```

used at line 97:

```php
if ($guarantorCount < self::MINIMUM_GUARANTORS) {
    // GUARANTORS_REQUIRED
}
```

**Source B — a configured column, already populated**

`account_type_requirements.min_guarantors`, seeded per account type
(`database/seeders/AccountTypeRequirementSeeder.php`), exposed by
`AccountTypeRequirementResource` as `minGuarantors`, and **already enforced at
registration** by `RegisterCustomerRequest::checkRelations()`.

## Why it matters

The registration wizard already reads the column: it tells the officer "at least N
guarantors are required for this account type" and refuses to save without them. The
loan gate then ignores it and applies its own hardcoded 1.

Today both happen to say 1 for the LOAN account type, so nothing is visibly broken. The
moment an administrator raises `min_guarantors` to 2, registration will demand two and
the loan gate will accept one — and the loan gate is the one that decides lending. A
configuration change would silently fail to take effect where it matters most.

## The change, exactly

`LoanEligibilityChecker` already has everything it needs; it just does not ask.

1. Inject `AccountTypeRequirementResolver` into the constructor
   (`app/Domain/Loans/Services/LoanEligibilityChecker.php`, around line 43).

2. Replace the constant read at line 97:

   ```php
   // before
   if ($guarantorCount < self::MINIMUM_GUARANTORS) {

   // after
   $minimum = $this->profiles->forCustomer($customer)->min_guarantors;

   if ($minimum > 0 && $guarantorCount < $minimum) {
   ```

3. Keep the `GUARANTORS_REQUIRED` violation code — the frontend renders it verbatim and
   must not have to learn a new one.

4. Delete `MINIMUM_GUARANTORS`. Leaving it as a fallback would recreate the same
   ambiguity one refactor later.

5. `> 0` matters: a savings account type may legitimately require none, and the current
   constant makes that unrepresentable.

## Before shipping it

Run the same impact check the document-enforcement change used:

- current `loanEligible()` count;
- the count after, with each account type's configured `min_guarantors`;
- any customer who would lose eligibility, by name.

With today's data both sources say 1, so the expectation is **no change** — and proving
that is the point.

## Tests to add

- a customer with one guarantor stays eligible while `min_guarantors` is 1;
- raising the account type's `min_guarantors` to 2 makes them ineligible, with
  `GUARANTORS_REQUIRED`;
- an account type with `min_guarantors` of 0 requires none;
- the registration wizard and the loan gate agree on the same number.
