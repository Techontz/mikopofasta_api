<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The switch that would make a category's required documents blocking —
 * shipped OFF, deliberately.
 *
 * WHY IT IS OFF. `customer_documents` holds zero rows. Sixteen customers are
 * loan-eligible today; if a category's document list became a KYC blocker this
 * morning, fifteen of them would stop being able to borrow before anyone had a
 * chance to collect a single file. That is not a stricter system, it is a
 * branch that cannot lend by Tuesday.
 *
 * So the requirement is BUILT and the enforcement is a per-account-type flag
 * that defaults to false. KycEvaluator reads it: false keeps today's behaviour
 * exactly — missing documents are reported on the profile and block nothing —
 * and true makes them a blocking requirement like any other. Flipping it is a
 * single UPDATE, reversible, and its effect is measurable before it is made.
 *
 * The grandfathering decision that governs when it flips is the business's,
 * not this migration's. The options are in the change report.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->boolean('requires_category_documents')
                ->default(false)
                ->after('requires_identity_document');
        });
    }

    public function down(): void
    {
        Schema::table('account_type_requirements', function (Blueprint $table): void {
            $table->dropColumn('requires_category_documents');
        });
    }
};
