<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two columns the configuration-driven registration form needs, and
 * nothing else.
 *
 * WHY SO LITTLE. Most of this feature already had a home. `customer_categories`
 * has held `dynamic_form_schema` (the field definitions) and
 * `required_documents` (document-type codes) since the table was created, and
 * `customers.dynamic_form_data` has held the answers. What was missing was not
 * storage — it was a richer field-definition vocabulary, which is JSON and
 * therefore needs no migration, and these two facts, which are columns.
 *
 *   form_title           The heading the registration step shows over a
 *                        customer type's own questions — "PUBLIC SERVANT
 *                        DETAILS" over the public-servant type's. Separate
 *                        from `name` because the name is the administrator's
 *                        label for the classification, often the institution's
 *                        own word for it, and the title is what the
 *                        customer-facing form calls the section. Null means
 *                        "use the name".
 *
 *   optional_documents   Document-type codes the file may contain but need not.
 *                        `required_documents` keeps its exact meaning — the
 *                        documents that MUST be produced, which KycEvaluator
 *                        and the wizard both already read — rather than being
 *                        widened into a list of objects with a flag, which
 *                        would have changed a contract three consumers depend
 *                        on in order to express one boolean.
 *
 * NO LOAN CATEGORY COLUMN. An earlier draft of this migration added
 * `customers.loan_product_id`, for a "Loan Category Name" field on step one of
 * registration. That field has been removed: which loan category a customer
 * eventually borrows under is a lending decision, made when there is a loan to
 * decide it for, and asking it during registration pre-judges an application
 * nobody has made. The column went with the field rather than being left behind
 * empty — nothing had ever written to it.
 *
 * Additive and backwards compatible: both columns are nullable, no existing row
 * changes, and every reader that predates them behaves exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->string('form_title', 150)->nullable()->after('description');
            $table->json('optional_documents')->nullable()->after('required_documents');
        });
    }

    public function down(): void
    {
        Schema::table('customer_categories', function (Blueprint $table): void {
            $table->dropColumn(['form_title', 'optional_documents']);
        });
    }
};
