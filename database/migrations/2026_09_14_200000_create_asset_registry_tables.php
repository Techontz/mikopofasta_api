<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Asset capital contributions and the Asset Registry.
     *
     *  - `capitals` gains a guarded reversal trail (asset contributions can be reversed through Ledger::reverse; reversed
     *    contributions no longer count as contributed capital).
     *  - `assets`: one row per asset contribution (1:1 with its `capitals` row). The contribution value and valuation
     *    snapshot are immutable; `current_value` changes only through a recorded revaluation.
     *  - `asset_events`: append-only history of every change.
     *  - `asset_documents`: files on the private disk (path only, never file content).
     */
    public function up(): void
    {
        Schema::table('capitals', function (Blueprint $table) {
            $table->timestamp('reversed_at')->nullable()->after('idempotency_key');
            $table->foreignId('reversal_journal_entry_id')->nullable()->after('reversed_at')->constrained('journal_entries');
            $table->string('reversal_reason', 500)->nullable()->after('reversal_journal_entry_id');
        });

        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capital_id')->unique()->constrained('capitals');
            $table->foreignId('share_holder_id')->constrained('share_holders');
            $table->unsignedInteger('sequence')->nullable();
            $table->string('asset_code', 20)->nullable();
            $table->string('qr_token', 36)->unique();
            $table->string('asset_type', 30);
            $table->string('name');
            $table->text('description');
            $table->unsignedInteger('quantity');
            $table->decimal('unit_value', 15, 2);
            $table->decimal('contribution_value', 15, 2);
            $table->decimal('current_value', 15, 2);
            $table->string('condition', 20)->nullable();
            $table->date('contributed_on');
            $table->foreignId('branch_id')->constrained('branches');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->string('valuation_method', 30);
            $table->date('valuation_date');
            $table->string('valued_by')->nullable();
            $table->string('valuation_reference')->nullable();
            $table->text('valuation_notes')->nullable();
            $table->json('specifications')->nullable();
            $table->string('ledger_account', 40);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->string('status', 30)->default('active');
            $table->foreignId('recorded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'sequence']);
            $table->unique(['company_id', 'asset_code']);
            $table->index(['company_id', 'asset_type', 'status']);
        });

        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('event', 40);
            $table->timestamp('occurred_at');
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->json('previous_value')->nullable();
            $table->json('new_value')->nullable();
            $table->decimal('amount_before', 15, 2)->nullable();
            $table->decimal('amount_after', 15, 2)->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['asset_id', 'occurred_at']);
        });

        Schema::create('asset_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 40);
            $table->string('original_name', 191);
            $table->string('path');
            $table->string('mime', 100);
            $table->unsignedBigInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_documents');
        Schema::dropIfExists('asset_events');
        Schema::dropIfExists('assets');

        Schema::table('capitals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversal_journal_entry_id');
            $table->dropColumn(['reversed_at', 'reversal_reason']);
        });
    }
};
