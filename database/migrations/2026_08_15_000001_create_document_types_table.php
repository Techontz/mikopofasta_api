<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tenth admin-managed lookup list — KYC document types.
 *
 * The upload panel asked staff to *type* the document type into a free-text
 * box, with "e.g. salary_slip" as the only guidance. The result is exactly
 * what a free-text key always produces: this database already holds a customer
 * document filed under `HJK`. It is attached to a real customer, it satisfies
 * nothing on any category's required list, and nobody can tell what it is.
 *
 * A category's `required_documents` is a list of these codes. When the code an
 * officer types has to match one of them character for character and nothing
 * checks that it does, the requirement is unenforceable by construction —
 * `salary_slip`, `salary slip` and `Salary_Slip` are three different documents
 * to the checklist and one document to the person uploading it.
 *
 * Same shape as the other nine lists (see the 2026_08_02 migration), so it
 * rides on the same controller, policy, resource and admin screen.
 *
 * STRUCTURE ONLY — NO ROWS. Which documents an institution requires is its own
 * policy. A fresh install starts with this table empty, and the registration
 * form says so rather than offering a guess. (Installations that ran this
 * migration before the rows were removed keep them; they are editable like any
 * other, and nothing deletes them.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->string('description', 255)->nullable();
            $table->unsignedSmallInteger('sort_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('document_types');
    }
};
