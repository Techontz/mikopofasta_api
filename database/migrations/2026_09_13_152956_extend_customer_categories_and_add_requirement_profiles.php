<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer types (customer_categories) gain the reference configuration: code, form title, sector,
     * dynamic_form_schema, omitted_standard_fields and flags. Existing rows (and their loan-product links)
     * are kept; the seeder matches them to the five reference codes and updates them in place.
     * Requirement profiles decide what registration makes mandatory (baseline row + optional overrides).
     */
    public function up(): void
    {
        Schema::table('customer_categories', function (Blueprint $table) {
            $table->string('code', 60)->nullable()->after('key');
            $table->text('description')->nullable()->after('name');
            $table->string('form_title')->nullable()->after('description');
            $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            $table->string('risk_tier', 10)->nullable()->after('sort_order');
            $table->string('sector', 20)->default('other')->after('risk_tier');
            $table->boolean('requires_sector')->default(false)->after('sector');
            $table->boolean('requires_employer')->default(false)->after('requires_sector');
            $table->boolean('requires_contract')->default(false)->after('requires_employer');
            $table->boolean('requires_salary')->default(false)->after('requires_contract');
            $table->json('optional_documents')->nullable()->after('required_documents');
            $table->json('dynamic_form_schema')->nullable()->after('form_schema');
            $table->json('omitted_standard_fields')->nullable()->after('dynamic_form_schema');
            $table->boolean('requires_extra_approval')->default(false)->after('omitted_standard_fields');
            $table->foreignId('created_by')->nullable()->after('requires_extra_approval')->constrained('employees')->nullOnDelete();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('account_type_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('account_type_id')->nullable();
            $table->string('account_type_name')->nullable();
            $table->foreignId('customer_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('requires_employment_details')->default(false);
            $table->boolean('requires_business_details')->default(false);
            $table->boolean('requires_bank_account')->default(false);
            $table->boolean('requires_card_details')->default(false);
            $table->unsignedTinyInteger('min_guarantors')->default(0);
            $table->unsignedTinyInteger('min_next_of_kin')->default(0);
            $table->boolean('requires_customer_category')->default(false);
            $table->boolean('requires_marital_status')->default(false);
            $table->boolean('requires_address')->default(false);
            $table->boolean('requires_identity_document')->default(false);
            $table->boolean('requires_category_documents')->default(false);
            $table->date('category_documents_enforced_from')->nullable();
            $table->boolean('requires_face_verification')->default(false);
            $table->boolean('requires_nida_verification')->default(false);
            $table->boolean('requires_otp_verification')->default(false);
            $table->text('guidance')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'account_type_id', 'customer_category_id'], 'atr_company_account_category_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account_type_requirements');

        Schema::table('customer_categories', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'code']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletes();
            $table->dropColumn([
                'code', 'description', 'form_title', 'sort_order', 'risk_tier', 'sector', 'requires_sector', 'requires_employer',
                'requires_contract', 'requires_salary', 'optional_documents', 'dynamic_form_schema', 'omitted_standard_fields', 'requires_extra_approval',
            ]);
        });
    }
};
