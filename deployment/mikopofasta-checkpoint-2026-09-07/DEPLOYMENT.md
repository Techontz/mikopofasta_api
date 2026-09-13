# Deployment — api.m-kopatz.co.tz

Checkpoint: **Register Account + requirement-configuration architecture**
Built 2026-09-07 · 74 files · 8 migrations (2 of them new)

---

## Composer is NOT required

Your server reports `composer: command not found`. It is not needed, and that is
established rather than assumed:

1. **`composer.json` and `composer.lock` are unchanged** in this checkpoint —
   `git status` reports no modification to either. No package was added, removed
   or upgraded.
2. **Every namespace the package imports is already installed**: `App\`,
   `Illuminate\`, `Carbon\`, `Symfony\`, `Laravel\`, `Database\`.
3. **New classes autoload without regenerating anything.** The previous package
   introduced `App\Http\Controllers\Loans\LoanProductBranchController`, and
   `GET /api/v1/loan-products/1/branches` on production answers **401** rather
   than 500 — the route resolves and the class loads. That proves the
   autoloader resolves `App\` by PSR-4 convention and is not classmap-
   authoritative, so the eight new classes in this package will be found the
   moment their files exist.

**Do not run `composer install`, `composer update` or `composer dump-autoload`.**

---

## 1. Back up first

Non-negotiable: migration `2026_09_06_000002` drops a column.

```bash
cd ~/domains/api.m-kopatz.co.tz
mysqldump -u YOUR_DB_USER -p YOUR_DB_NAME > ~/backup-before-checkpoint-$(date +%Y%m%d-%H%M).sql
ls -lh ~/backup-before-checkpoint-*.sql
```

Confirm the file is non-empty before continuing.

## 2. Upload

Upload `mikopofasta-checkpoint-2026-09-07.tar.gz` to the Laravel application
root — the directory that already contains `app/`, `database/`, `routes/`,
`artisan` — and extract it there.

The archive has **no wrapper directory**. Its root entries are exactly:

```
app/  database/  tests/  MANIFEST.md  DEPLOYMENT.md
```

so extracting merges `app/…` onto `app/…` and `database/…` onto `database/…`.
Existing files are overwritten; nothing else in the application is touched.

Verify one new file arrived:

```bash
ls -l app/Domain/Treasury/Enums/AccountUsage.php
ls -l database/migrations/2026_09_06_000002_make_bank_accounts_company_payment_channels.php
```

## 3. Clear the caches

The config and route caches hold the *old* code. Clear before migrating.

```bash
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear
```

## 4. Check what will run, then run it

```bash
php artisan migrate:status | tail -20
```

Exactly **two** should read `Pending`:

```
2026_09_06_000001_scope_registration_requirements_to_customer_category
2026_09_06_000002_make_bank_accounts_company_payment_channels
```

If anything else is pending, stop and send me the output.

```bash
php artisan migrate --force
```

`--force` because production is a non-interactive environment; it does not skip
any safety check.

## 5. Re-cache

```bash
php artisan config:cache
php artisan route:cache
```

Do **not** run `php artisan optimize:clear` afterwards — it would undo this.

## 6. Verify

```bash
# Both should answer 401 (route exists, authentication required)
curl -s -o /dev/null -w "bank-accounts:  %{http_code}\n" https://api.m-kopatz.co.tz/api/v1/bank-accounts
curl -s -o /dev/null -w "requirements:   %{http_code}\n" https://api.m-kopatz.co.tz/api/v1/registration/requirements

# Should still be 404 — Profit Distribution is deliberately not deployed
curl -s -o /dev/null -w "distribution:   %{http_code}\n" https://api.m-kopatz.co.tz/api/v1/distribution-setting

# The application boots
php artisan about | head -20
```

Then sign in to the app and open **Bank → Register Account**. You should see the
Bank / Mobile Money selector, no Branch field, and your existing accounts still
listed with their balances unchanged.

---

## What the two new migrations do to your data

### `2026_09_06_000001` — requirement scoping

- Adds `customer_category_id` to `account_type_requirements`.
- Makes 14 requirement columns nullable. **NULL means "inherit from the next
  profile down", never "not required."**
- Replaces `unique(account_type_id)` with `unique(account_type_id, customer_category_id)`.

**Existing rows keep every value they hold**, so every account type resolves
exactly as it does today. Nothing changes until somebody creates a customer-type
profile from the admin screen.

### `2026_09_06_000002` — company payment channels

- **Drops `bank_accounts.branch_id`.** Every row's value is already NULL, so no
  information is lost. (Branch remains on bank *transactions*, where it means
  which branch initiated a movement.)
- Adds `account_type` (`bank`/`mno`, defaults `bank`), `usage`
  (`collection`/`disbursement`/`both`, defaults `both`), `bank_id`,
  `mobile_money_provider_id`.
- Backfills `bank_id` where the stored `bank_name` matches a bank in master
  data. A name that matches nothing keeps working on its text.
- Adds two **database-generated** uniqueness keys. No application code writes
  them, so no insert path can get them wrong.

Existing accounts land on `account_type = bank`, `usage = both` — which is how
they behave today, so no account stops working and no selector loses an option.

**`chart_account_id` is not touched, and neither is any journal entry, journal
line or account balance.** Balances continue to come from the ledger.

---

## Rollback

Both migrations were applied *and* rolled back against a real MySQL database
during development, with row values compared before and after.

```bash
php artisan migrate:rollback --step=2
php artisan config:cache && php artisan route:cache
```

Then re-upload the previous package's versions of the 64 modified files.

One deliberate guard: `2026_09_06_000002`'s `down()` **refuses to run** if any
mobile money account has been registered, because a wallet cannot be expressed
as a bank account and neither mangling nor deleting it is acceptable. Remove
those accounts first if you genuinely need to roll back.

---

## If something goes wrong

- **500 with "Class ... not found"** — a file did not upload. Re-extract and
  re-run step 3.
- **Migration fails** — nothing is half-applied except DDL, which MySQL does not
  roll back. Send me the error before retrying; restore from step 1 if needed.
- **Register Account shows a Branch field** — the browser cached the old
  frontend bundle. Hard-refresh; the backend is unaffected.
