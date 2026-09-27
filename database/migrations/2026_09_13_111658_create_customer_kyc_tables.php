<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KYC registration data (Documents: CUSTOMER REGISTRATION OVERVIEW): NIDA + OTP verification,
     * live face verification, next of kin, residence, bank details, category answers and documents.
     */
    public function up(): void
    {
        Schema::create('customer_kyc', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('nida_number', 20)->index();
            $table->json('nida_data');
            $table->timestamp('nida_verified_at')->nullable();
            $table->string('otp_phone')->nullable();
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamp('face_verified_at')->nullable();
            $table->decimal('face_liveness_score', 5, 4)->nullable();
            $table->decimal('face_match_score', 5, 4)->nullable();
            $table->string('face_photo')->nullable();
            $table->string('face_reference')->nullable();
            $table->unsignedSmallInteger('face_attempts')->default(0);
            $table->json('category_answers')->nullable();
            $table->timestamp('category_assigned_at')->nullable();
            $table->foreignId('category_assigned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_next_of_kin', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('phone');
            $table->string('relationship')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_residences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('region_code', 10);
            $table->string('region_name');
            $table->string('district_code', 12);
            $table->string('district_name');
            $table->string('ward_code', 16);
            $table->string('ward_name');
            $table->string('street_name');
            $table->string('ownership', 10);
            $table->timestamps();
        });

        Schema::create('customer_bank_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('account_number');
            $table->string('account_name');
            $table->string('check_number')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });

        Schema::create('customer_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->index(['customer_id', 'document_type']);
        });

        Schema::create('streets', function (Blueprint $table) {
            $table->id();
            $table->string('ward_code', 16);
            $table->string('name');
            $table->timestamps();
            $table->unique(['ward_code', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('streets');
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('customer_bank_details');
        Schema::dropIfExists('customer_residences');
        Schema::dropIfExists('customer_next_of_kin');
        Schema::dropIfExists('customer_kyc');
    }
};
