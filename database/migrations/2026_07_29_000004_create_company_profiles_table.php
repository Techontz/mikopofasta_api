<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The organization's own profile, backing the Administration module's
     * Company Profile screen.
     *
     * This table is NOT in backend spec §2 — the frontend's
     * types/organization.ts introduces it and says so plainly ("Not in the
     * original backend schema (no dedicated table) — a small, clearly-scoped
     * addition ... following the same singleton-row pattern most Laravel apps
     * use for org-wide settings"). It is carried over here because the
     * frontend is the contract.
     *
     * Singleton: exactly one row, id 1. The frontend types its id as the
     * literal string "company-profile", so the resource emits that constant
     * rather than the numeric key — see CompanyProfileResource.
     */
    public function up(): void
    {
        Schema::create('company_profiles', function (Blueprint $table): void {
            $table->id();

            $table->string('legal_name', 150);
            $table->string('trading_name', 150);
            $table->string('registration_number', 60);
            $table->string('tin_number', 60);
            $table->string('phone', 20);
            $table->string('email', 150);
            $table->string('address', 255);

            /*
             * Which branch is the registered head office. Kept in step with
             * `branches.is_head_office` by SetHeadOfficeAction; restrictOnDelete
             * because deleting the HQ branch is already blocked outright.
             */
            $table->foreignId('headquarters_branch_id')->nullable()->constrained('branches')->restrictOnDelete()->cascadeOnUpdate();

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_profiles');
    }
};
