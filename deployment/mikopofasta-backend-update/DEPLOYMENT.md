# Deployment — MikopoFasta backend update

Brings a production server running commit **`d531271`** up to the current
development state, minus the deliberately excluded Profit Distribution work.

52 files, 5 migrations. **Every path in this package is already relative to the
Laravel application root**, so copying the contents into the root puts every
file exactly where it belongs. Nothing to find by hand.

Every change is additive: no column is dropped, no table emptied, no existing
row rewritten.

---

## A. Back up the database

Not optional, and nothing below undoes a mistake for you.

```bash
mysqldump -u <user> -p <database> > ~/mikopofasta-db-$(date +%F-%H%M).sql
ls -lh ~/mikopofasta-db-*.sql          # confirm it is not empty
```

## B. Record the counts you will check afterwards

```bash
mysql -u <user> -p <database> -e "
  SELECT (SELECT COUNT(*) FROM customers)          AS customers,
         (SELECT COUNT(*) FROM loans)              AS loans,
         (SELECT COUNT(*) FROM customer_documents) AS documents,
         (SELECT COUNT(*) FROM branches)           AS branches;"
```

Write the numbers down. Step L compares against them.

## C. Back up the current production files

```bash
cd /home/apimkopa/domains/api.m-kopatz.co.tz/app
tar -czf ~/mikopofasta-files-$(date +%F-%H%M).tar.gz app database routes tests
```

This is what a file-level rollback restores from.

## D. Copy the update package into the Laravel root

```bash
cd /home/apimkopa/domains/api.m-kopatz.co.tz/app
tar -xzf /path/to/mikopofasta-backend-update.tar.gz -C /tmp/
cp -r /tmp/mikopofasta-backend-update/* .
```

Or, from an already-extracted directory:

```bash
cd /home/apimkopa/domains/api.m-kopatz.co.tz/app
cp -r /path/to/mikopofasta-backend-update/* .
```

`cp -r` merges directories and overwrites only the 52 files in `MANIFEST.md`.
Nothing else under `app/`, `database/`, `routes/` or `tests/` is touched.

> `MANIFEST.md` and `DEPLOYMENT.md` sit at the package root and will be copied
> into the application root too. They are harmless; delete them if you prefer:
> `rm -f MANIFEST.md DEPLOYMENT.md`

If you do not want the tests on production, skip them:

```bash
cp -r /tmp/mikopofasta-backend-update/app      .
cp -r /tmp/mikopofasta-backend-update/database .
cp -r /tmp/mikopofasta-backend-update/routes   .
```

## E. Regenerate the autoloader

**Required** — five of these files are new classes. `composer install` is *not*
required: no dependency changed and `composer.json` is untouched.

```bash
composer dump-autoload --optimize
```

## F. Run the migrations

```bash
php artisan migrate --force
```

Five will run:

```
2026_09_01_000001_add_activation_to_customer_categories
2026_09_02_000001_add_reference_fields_to_loan_products
2026_09_02_000002_create_loan_product_branches_table
2026_09_03_000001_add_registration_form_configuration
2026_09_04_000001_link_id_types_to_document_types
```

All additive. Every new column is nullable or defaulted;
`customer_categories.is_active` defaults **true**, so existing customer types
stay offered, and an empty `loan_product_branches` means every branch, so
existing products stay available everywhere.

**No seeder to run.** Nothing in this package inserts business data. The new
features stay dormant until an administrator configures them — see the note at
the end.

## G. Clear caches

```bash
php artisan optimize:clear
```

## H. Cache the config

```bash
php artisan config:cache
```

## I. Cache the routes

```bash
php artisan route:cache
```

This matters: a stale route cache serves the old route table however correct the
file is.

## J. Cache the events

```bash
php artisan event:cache
```

If your deployment does not normally cache events, skip it — nothing here
registers a new listener.

Restart queue workers if you run any; a running worker does not pick up new
code:

```bash
php artisan queue:restart
```

## K. Verify the routes

```bash
php artisan route:list --path=master-data
php artisan route:list --path=loan-products
```

Expect these four to be present:

| Method | URI | Name |
|---|---|---|
| GET | `api/v1/master-data` | `api.v1.master-data.all` |
| GET | `api/v1/loan-products/{product}/branches` | `api.v1.loan-products.branches.index` |
| POST | `api/v1/loan-products/{product}/branches` | `api.v1.loan-products.branches.store` |
| DELETE | `api/v1/loan-products/{product}/branches/{branch}` | `api.v1.loan-products.branches.destroy` |

And confirm nothing excluded arrived:

```bash
php artisan route:list | grep distribution-setting     # expect NO output
```

## L. Verify the application

**Schema:**

```bash
php artisan migrate:status | tail -6      # all five should read "Ran"
```

```sql
SHOW COLUMNS FROM customer_categories LIKE 'form_title';
SHOW COLUMNS FROM customer_categories LIKE 'optional_documents';
SHOW COLUMNS FROM customer_categories LIKE 'is_active';
SHOW COLUMNS FROM id_types            LIKE 'document_type_id';
SHOW COLUMNS FROM loan_products       LIKE 'approval_stage_id';
SHOW TABLES LIKE 'loan_product_branches';
```

**Nothing lost** — compare against step B:

```bash
mysql -u <user> -p <database> -e "
  SELECT (SELECT COUNT(*) FROM customers)          AS customers,
         (SELECT COUNT(*) FROM loans)              AS loans,
         (SELECT COUNT(*) FROM customer_documents) AS documents,
         (SELECT COUNT(*) FROM branches)           AS branches;"
```

**API**, as an authenticated user:

```bash
curl -s -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  https://api.m-kopatz.co.tz/api/v1/master-data?active=1 | head -c 400
```

One JSON object keyed by list slug. This single call replaces the fourteen the
registration screen used to make — the change that removed the HTTP 429.

```bash
curl -s -H "Authorization: Bearer <token>" -H "Accept: application/json" \
  https://api.m-kopatz.co.tz/api/v1/customers/<existing-id> | head -c 400
```

An existing customer still reads correctly.

## M. Rollback

**Reverse the schema** — every migration has a tested `down()`:

```bash
php artisan migrate:rollback --step=5 --force
```

Verified: this drops exactly the five migrations' columns and the one table, and
leaves every existing row intact.

**Restore the files:**

```bash
cd /home/apimkopa/domains/api.m-kopatz.co.tz/app
tar -xzf ~/mikopofasta-files-<stamp>.tar.gz
composer dump-autoload --optimize
php artisan optimize:clear && php artisan config:cache && php artisan route:cache
```

**Full database restore**, if a migration failed part-way. MySQL has no
transactional DDL, so assume a half-changed table and restore rather than roll
back:

```bash
mysql -u <user> -p <database> < ~/mikopofasta-db-<stamp>.sql
```

**Never run `migrate:fresh`, `db:wipe` or `migrate:reset` on production.** The
ledger is append-only. `AppServiceProvider` blocks them in production; the rule
is worth stating anyway.

---

## After deployment — configuration you must do by hand

Nothing is seeded. Two features do nothing until configured:

1. **ID Type → Document Type links** —
   *Administration → Master Data → ID Types → edit → "Document that proves it"*.
   **This one has a visible consequence:** once an ID type is linked,
   registration shows a required upload slot for that document and **will not
   save without it**. Link one, test the registration screen, then continue.

2. **Customer Type registration forms** —
   *Administration → Customer Types → row → Registration form*.
   Until configured, a customer type asks nothing beyond the basic information —
   exactly as production behaves today.

Neither is required for the deployment to be correct. Both are required before
the new registration behaviour appears.
