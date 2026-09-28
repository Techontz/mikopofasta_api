<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer module (CUSTOMER_MODULE_IMPLEMENTATION.md §1). Additive only — existing columns used by loans,
     * payments and reports stay. Adaptations to this project's existing columns:
     *  - `date_of_birth` is the spec's `dob`; `dependents` is `dependents_count`; `customer_documents.size` is
     *    `size_bytes`; `customer_bank_details.phone` is `phone_number`.
     *  - The spec's customer `status` (active / suspended / frozen) is stored in `account_status`, because
     *    `status` already holds the live loan lifecycle (pending / open / out / close) used across the system.
     *  - `customer_next_of_kin` and `guarantors` gain the spec's single `name` (+ address, …) columns.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_number', 20)->nullable()->unique()->after('customer_code');
            $table->string('middle_name')->nullable()->change();
            $table->string('work_status')->nullable()->change();
            $table->string('district')->nullable()->change();
            $table->string('ward')->nullable()->change();
            $table->string('street')->nullable()->change();
            $table->string('kyc_status')->default('incomplete')->change();
            $table->unique('phone');

            // Identity
            $table->unsignedBigInteger('id_type_id')->nullable()->index()->after('id_number');
            $table->string('nida_number', 30)->nullable()->unique()->after('id_type_id');
            $table->string('national_id_number', 40)->nullable()->unique();
            $table->string('voter_id_number', 40)->nullable()->unique();
            $table->string('driver_licence_number', 40)->nullable()->unique();
            $table->string('passport_number', 30)->nullable()->unique();
            $table->string('work_id_number', 60)->nullable();
            $table->string('tin_number', 30)->nullable()->unique();

            // Person / household
            $table->string('alternative_phone', 20)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('nationality', 60)->nullable();
            $table->unsignedBigInteger('marital_status_id')->nullable()->index();
            $table->string('residence_type', 10)->nullable();

            // Address
            $table->unsignedBigInteger('district_id')->nullable()->index();
            $table->unsignedBigInteger('ward_id')->nullable()->index();
            $table->string('ward_name', 120)->nullable();
            $table->unsignedBigInteger('street_id')->nullable();
            $table->string('street_name', 120)->nullable();
            $table->string('village', 120)->nullable();
            $table->string('house_number', 60)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('landmark')->nullable();

            // Employment
            $table->string('place_of_employment', 150)->nullable();
            $table->unsignedBigInteger('basic_salary')->nullable();
            $table->unsignedBigInteger('take_home')->nullable();
            $table->date('retirement_date')->nullable();
            $table->string('occupation', 150)->nullable();
            $table->string('employer', 150)->nullable();
            $table->unsignedBigInteger('employer_id')->nullable();
            $table->string('department', 150)->nullable();
            $table->string('council_number', 60)->nullable();
            $table->string('employment_type', 60)->nullable();
            $table->string('work_type', 60)->nullable();
            $table->unsignedBigInteger('employment_type_id')->nullable();
            $table->unsignedBigInteger('work_type_id')->nullable();
            $table->unsignedBigInteger('occupation_id')->nullable();
            $table->unsignedBigInteger('sector_id')->nullable();
            $table->unsignedBigInteger('sector_category_id')->nullable();
            $table->unsignedBigInteger('contract_type_id')->nullable();
            $table->date('contract_expiry_date')->nullable();

            // Business
            $table->string('business_name', 150)->nullable();
            $table->string('business_address')->nullable();

            // Payment
            $table->string('payment_method', 10)->nullable();
            $table->unsignedBigInteger('bank_id')->nullable()->index();
            $table->string('bank_name', 100)->nullable();
            $table->string('bank_branch', 100)->nullable();
            $table->string('account_name', 150)->nullable();
            $table->unsignedBigInteger('mobile_money_provider_id')->nullable()->index();
            $table->string('mobile_money_provider', 60)->nullable();
            $table->string('wallet_number', 30)->nullable();
            $table->string('card_last_four', 4)->nullable();
            $table->unsignedTinyInteger('card_expiry_month')->nullable();
            $table->unsignedSmallInteger('card_expiry_year')->nullable();

            // Classification
            $table->json('dynamic_form_data')->nullable();
            $table->unsignedBigInteger('account_type_id')->nullable();
            $table->unsignedBigInteger('customer_type_id')->nullable();
            $table->unsignedBigInteger('loan_type_id')->nullable();

            // Ownership
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('registration_source', 30)->nullable();
            $table->string('created_device')->nullable();
            $table->string('updated_device')->nullable();

            // Face / KYC
            $table->string('photo_path')->nullable();
            $table->unsignedBigInteger('active_face_scan_id')->nullable();
            $table->string('face_scan_status', 10)->nullable();
            $table->unsignedTinyInteger('face_scan_quality')->nullable();
            $table->string('face_scan_version', 64)->nullable();
            $table->timestamp('face_scanned_at')->nullable();
            $table->foreignId('face_scanned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('face_verified_at')->nullable();
            $table->timestamp('nida_verified_at')->nullable();
            $table->timestamp('otp_verified_at')->nullable();

            // Account status and approval
            $table->string('account_status', 12)->default('active');
            $table->string('status_reason')->nullable();
            $table->text('status_remarks')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->foreignId('status_changed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('approval_status', 14)->default('not_required');
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->softDeletes();
        });

        // customer_number: CU- + zero-padded sequence, in registration order.
        $sequence = 0;
        DB::table('customers')->whereNull('customer_number')->orderBy('id')->each(function (object $customer) use (&$sequence): void {
            $sequence++;
            DB::table('customers')->where('id', $customer->id)->update(['customer_number' => sprintf('CU-%06d', $sequence)]);
        });

        // Payment method: wallet → mno; else account number or bank → bank; else null (no wallets exist before this migration).
        DB::table('customers')->whereNotNull('account_number')->where('account_number', '!=', '')->update(['payment_method' => 'bank']);

        // KYC status now uses incomplete / completed. Customers approved under the previous flow are complete.
        DB::table('customers')->where('kyc_status', 'approved')->update(['kyc_status' => 'completed']);
        DB::table('customers')->where('kyc_status', '!=', 'completed')->update(['kyc_status' => 'incomplete']);

        Schema::table('customer_next_of_kin', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('customer_id');
            $table->string('address')->nullable()->after('relationship');
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
        });
        DB::table('customer_next_of_kin')->whereNull('name')->orderBy('id')->each(function (object $row): void {
            DB::table('customer_next_of_kin')->where('id', $row->id)->update([
                'name' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name]))),
            ]);
        });

        Schema::table('guarantors', function (Blueprint $table) {
            $table->string('name', 150)->nullable()->after('loan_id');
            $table->string('nida_number', 30)->nullable()->after('phone');
            $table->string('address')->nullable()->after('relationship');
            $table->string('occupation', 150)->nullable()->after('address');
            $table->string('first_name')->nullable()->change();
            $table->string('last_name')->nullable()->change();
        });
        DB::table('guarantors')->whereNull('name')->orderBy('id')->each(function (object $row): void {
            DB::table('guarantors')->where('id', $row->id)->update([
                'name' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name]))),
            ]);
        });

        Schema::create('face_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            foreach (['quality_score', 'brightness_score', 'blur_score', 'distance_score', 'centering_score', 'eyes_open_score'] as $score) {
                $table->unsignedTinyInteger($score);
            }
            $table->string('scanner_version', 64);
            $table->boolean('liveness_passed');
            $table->boolean('pose_sequence_completed');
            $table->json('checks');
            $table->string('capture_device', 191)->nullable();
            $table->string('capture_resolution', 16)->nullable();
            $table->unsignedInteger('capture_duration_ms')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('photo_path');
            $table->foreignId('scanned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->index(['customer_id', 'is_active']);
        });

        Schema::create('customer_registration_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('label', 160);
            $table->string('phone', 20)->nullable()->index();
            $table->json('payload');
            $table->unsignedTinyInteger('step')->default(0);
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->index(['created_by', 'submitted_at']);
            $table->index(['branch_id', 'submitted_at']);
        });

        Schema::create('customer_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_registration_drafts');
        Schema::dropIfExists('face_scans');

        Schema::table('guarantors', function (Blueprint $table) {
            $table->dropColumn(['name', 'nida_number', 'address', 'occupation']);
        });
        Schema::table('customer_next_of_kin', function (Blueprint $table) {
            $table->dropColumn(['name', 'address']);
        });

        DB::table('customers')->where('kyc_status', 'completed')->update(['kyc_status' => 'approved']);
        DB::table('customers')->where('kyc_status', 'incomplete')->update(['kyc_status' => 'pending']);

        Schema::table('customers', function (Blueprint $table) {
            foreach (['created_by', 'face_scanned_by', 'status_changed_by', 'approved_by'] as $foreign) {
                $table->dropConstrainedForeignId($foreign);
            }
            $table->dropUnique(['phone']);
            foreach (['nida_number', 'national_id_number', 'voter_id_number', 'driver_licence_number', 'passport_number', 'tin_number', 'customer_number'] as $unique) {
                $table->dropUnique([$unique]);
            }
            foreach (['id_type_id', 'marital_status_id', 'district_id', 'ward_id', 'bank_id', 'mobile_money_provider_id'] as $index) {
                $table->dropIndex([$index]);
            }
            $table->dropSoftDeletes();
            $table->dropColumn([
                'customer_number', 'id_type_id', 'nida_number', 'national_id_number', 'voter_id_number', 'driver_licence_number', 'passport_number',
                'work_id_number', 'tin_number', 'alternative_phone', 'email', 'nationality', 'marital_status_id', 'residence_type', 'district_id',
                'ward_id', 'ward_name', 'street_id', 'street_name', 'village', 'house_number', 'postal_code', 'landmark', 'place_of_employment',
                'basic_salary', 'take_home', 'retirement_date', 'occupation', 'employer', 'employer_id', 'department', 'council_number',
                'employment_type', 'work_type', 'employment_type_id', 'work_type_id', 'occupation_id', 'sector_id', 'sector_category_id',
                'contract_type_id', 'contract_expiry_date', 'business_name', 'business_address', 'payment_method', 'bank_id', 'bank_name',
                'bank_branch', 'account_name', 'mobile_money_provider_id', 'mobile_money_provider', 'wallet_number', 'card_last_four',
                'card_expiry_month', 'card_expiry_year', 'dynamic_form_data', 'account_type_id', 'customer_type_id', 'loan_type_id',
                'registration_source', 'created_device', 'updated_device', 'photo_path', 'active_face_scan_id', 'face_scan_status',
                'face_scan_quality', 'face_scan_version', 'face_scanned_at', 'face_verified_at', 'nida_verified_at', 'otp_verified_at',
                'account_status', 'status_reason', 'status_remarks', 'status_changed_at', 'approval_status', 'approved_at', 'rejection_reason',
            ]);
            $table->string('kyc_status')->default('pending')->change();
        });
    }
};
