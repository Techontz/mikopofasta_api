<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\KycStatus;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Enums\ResidenceType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backend spec §2.4 — `customers`.
     *
     * The three verification timestamps are the KYC audit trail: NIDA lookup,
     * NIDA OTP, and liveness capture (§9). They are timestamps rather than
     * booleans on purpose — "verified" without "when" is not an audit trail.
     *
     * Four columns are not in §2.4 and come from the frontend, which adds them
     * to express §2.3's `requires_extra_approval`: `approval_status`,
     * `approved_by`, `approved_at`, `rejection_reason`.
     *
     * `nida_number` and `phone` are UNIQUE. Note this makes them unique across
     * soft-deleted rows too, which is the correct reading: a national ID
     * belongs to one person permanently, and re-registering someone who was
     * removed should surface as a conflict rather than silently create a
     * duplicate identity.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('customer_number', 30)->unique();
            $table->string('nida_number', 30)->unique();

            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->date('dob');
            $table->enum('gender', Gender::values());
            $table->string('phone', 20)->unique();

            // Liveness/NIDA photo. Stored on the private `kyc` disk; never
            // served as a raw path (see CustomerDocumentResource).
            $table->string('photo_path', 255)->nullable();

            $table->timestamp('nida_verified_at')->nullable();
            $table->timestamp('otp_verified_at')->nullable();
            $table->timestamp('face_verified_at')->nullable();

            $table->enum('marital_status', MaritalStatus::values())->nullable();

            // Structured address — the region → district → ward → street chain
            // from §2.2, all four levels retained.
            $table->foreignId('region_id')->nullable()->constrained('regions')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('district_id')->nullable()->constrained('districts')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('ward_id')->nullable()->constrained('wards')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('street_id')->nullable()->constrained('streets')->restrictOnDelete()->cascadeOnUpdate();

            $table->enum('residence_type', ResidenceType::values())->nullable();

            $table->foreignId('customer_category_id')->nullable()
                ->constrained('customer_categories')->restrictOnDelete()->cascadeOnUpdate();

            // Validated against the category's dynamic_form_schema at write time.
            $table->json('dynamic_form_data')->nullable();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete()->cascadeOnUpdate();

            $table->enum('kyc_status', KycStatus::values())->default(KycStatus::Incomplete->value);
            $table->enum('status', CustomerStatus::values())->default(CustomerStatus::Active->value);

            $table->enum('approval_status', CustomerApprovalStatus::values())
                ->default(CustomerApprovalStatus::NotRequired->value);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();
            $table->softDeletes();

            $table->index('branch_id');
            $table->index('customer_category_id');
            $table->index('kyc_status');
            $table->index('status');
            $table->index('approval_status');

            // Backs the frontend's "search by name, phone, or customer #".
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
