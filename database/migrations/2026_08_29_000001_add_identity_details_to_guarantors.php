<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\MaritalStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A guarantor becomes identifiable.
 *
 * WHAT WAS MISSING. `guarantors` held name, phone, National ID, relationship,
 * address and occupation — and the branch's own loan application form has
 * always asked for gender, marital status and a passport photograph as well.
 * Three fields with nowhere to go meant the loan screen either dropped them
 * silently or did not ask, and neither is acceptable for somebody who is
 * legally on the hook for a debt. A guarantor is a person the institution may
 * one day have to find.
 *
 * ADDITIVE AND NULLABLE, both deliberately. Every column here is new and
 * nullable, so the migration cannot fail on existing data and cannot make an
 * existing guarantor invalid: the 26 already on the books have no gender and
 * no photograph, and inventing one for them would be worse than leaving it
 * blank. Nothing is dropped, renamed or re-typed. The loan gate counts
 * guarantors and does not inspect these fields, so no customer's eligibility
 * moves either way.
 *
 * THE ENUMS ARE THE APPLICATION'S. `gender` and `marital_status` take their
 * values from `Gender::values()` and `MaritalStatus::values()` — the same
 * enums `customers` uses for the same two facts, cast the same way on the
 * model. Writing the strings out here would give the database a second
 * opinion about what a marital status is.
 *
 * THE PASSPORT FOLLOWS `customer_documents`, NOT A NEW ARCHITECTURE. The file
 * lives on the private `kyc` disk through `KycDocumentStorage`, and only its
 * path is stored — never returned to a client, which gets a signed, expiring
 * URL instead (spec §1: "signed, time-limited URLs only, never public disk").
 * The three companion columns are the ones `customer_documents` already keeps:
 * a passport may be a PDF or an image, so the download response cannot assume
 * a content type the way the always-JPEG face capture can.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guarantors', function (Blueprint $table): void {
            /* Beside the identity they describe, and in the order the branch's
               form asks for them. */
            $table->enum('gender', Gender::values())->nullable()->after('phone');
            $table->enum('marital_status', MaritalStatus::values())->nullable()->after('gender');

            /*
             * Path on the PRIVATE `kyc` disk. Never returned to a client — the
             * resource emits a signed, expiring download URL instead, exactly
             * as `customer_documents.file_path` does.
             */
            $table->string('passport_path', 255)->nullable()->after('occupation');
            $table->string('passport_original_name', 255)->nullable()->after('passport_path');
            $table->string('passport_mime_type', 100)->nullable()->after('passport_original_name');
            $table->unsignedBigInteger('passport_size_bytes')->nullable()->after('passport_mime_type');
        });
    }

    public function down(): void
    {
        /*
         * Drops the columns and nothing else. The files themselves are left on
         * the `kyc` disk: a schema rollback is not a decision to destroy
         * regulated documents, and re-running `up()` would find them again.
         * Purging orphaned KYC files is a deliberate operational act, not a
         * side effect of a migration.
         */
        Schema::table('guarantors', function (Blueprint $table): void {
            $table->dropColumn([
                'gender',
                'marital_status',
                'passport_path',
                'passport_original_name',
                'passport_mime_type',
                'passport_size_bytes',
            ]);
        });
    }
};
