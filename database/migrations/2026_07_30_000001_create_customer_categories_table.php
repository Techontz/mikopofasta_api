<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\CategorySector;
use App\Domain\Customers\Enums\RiskTier;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.3 — `customer_categories`.
     *
     * The category is the rule engine: it drives KYC document requirements,
     * the risk tier, whether registration needs extra approval, and (through
     * `category_product_eligibility` in Phase 5) which loan products the
     * customer may apply for. It is deliberately a separate entity from
     * LoanProduct and RepaymentSchedule — the three-entity split locked in
     * before the frontend was built.
     *
     * `sector` is not in §2.3. The frontend adds it (types/customer.ts) purely
     * to decide whether the wizard's dynamic step is labelled "Employment
     * Details" or "Business Information"; it carries no rule.
     */
    public function up(): void
    {
        Schema::create('customer_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 40)->unique();
            $table->enum('risk_tier', RiskTier::values());
            $table->enum('sector', CategorySector::values())->default(CategorySector::Other->value);

            // e.g. ["salary_slip","employer_letter"] — checked against
            // uploaded customer_documents by the KYC evaluator.
            $table->json('required_documents');

            // Field definitions the registration wizard renders, and which
            // `customers.dynamic_form_data` is validated against at write time.
            $table->json('dynamic_form_schema');

            $table->boolean('requires_extra_approval')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();
            $table->softDeletes();

            $table->index('risk_tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_categories');
    }
};
