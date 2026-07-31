<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\GuarantorRelationship;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything hanging off a customer.
     *
     * Two of these are in backend spec §2.4 — `customer_bank_details` and
     * `customer_documents`. The other three are frontend additions that
     * types/guarantor.ts, types/next-of-kin.ts and types/customer-note.ts each
     * flag explicitly as "not in the original 54": the registration wizard
     * collects guarantors and next-of-kin as first-class, independently
     * manageable records, and the profile has a CRM-style notes panel.
     *
     * All five cascade on customer delete. That is safe precisely because a
     * customer is only ever soft-deleted (§2 cross-cutting rule), so the
     * cascade never actually fires — it exists so a deliberate hard delete in
     * a maintenance context cannot leave orphans.
     */
    public function up(): void
    {
        Schema::create('customer_bank_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('bank_name', 100);
            $table->string('account_number', 50);
            $table->string('account_name', 150);

            // Public-servant payroll check number — collected for the salary
            // categories (§2.4).
            $table->string('check_number', 50)->nullable();
            $table->string('phone_number', 20)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        Schema::create('customer_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete()->cascadeOnUpdate();

            // Free text, matching the category's `required_documents` entries
            // (e.g. "salary_slip"). The frontend's upload panel lets staff
            // type an arbitrary type, so this is not an ENUM.
            $table->string('document_type', 60);

            // Path on the PRIVATE `kyc` disk. Never returned to a client —
            // the resource emits a signed, expiring download URL instead.
            $table->string('file_path', 255);

            $table->string('original_name', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->timestamps();

            $table->index('customer_id');
            $table->index(['customer_id', 'document_type']);
        });

        Schema::create('guarantors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('name', 150);
            $table->string('phone', 20);
            $table->string('nida_number', 30)->nullable();
            $table->enum('relationship', GuarantorRelationship::values());
            $table->string('address', 255)->nullable();
            $table->string('occupation', 150)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        Schema::create('next_of_kin', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('name', 150);
            $table->enum('relationship', GuarantorRelationship::values());
            $table->string('phone', 20);
            $table->string('address', 255)->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });

        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete()->cascadeOnUpdate();

            // RESTRICT, not nullOnDelete: a note without an author is a note
            // nobody is accountable for. Users are soft-deleted anyway.
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();

            $table->text('note');
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('next_of_kin');
        Schema::dropIfExists('guarantors');
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('customer_bank_details');
    }
};
