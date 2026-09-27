<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shares Management: the share register is the authoritative source of ownership.
     *
     *  - share_structures: one per company — initial capital basis, initial shares, initial share value and the
     *    optional authorised share limit.
     *  - share_valuations: append-only share value events (previous → new value, effective date, totals at the time).
     *  - share_transactions: append-only ledger of share movements (initial allocation, issuance, bonus issuance,
     *    transfer, cancellation, adjustment, reversal); corrections are reversal rows, never edits.
     *  - share_positions: current shares per shareholder, maintained only inside the database transaction that
     *    records a movement (so it can be row-locked) and always equal to a replay of share_transactions.
     */
    public function up(): void
    {
        Schema::create('share_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('authorised_shares')->nullable();
            $table->decimal('initial_capital_basis', 20, 2);
            $table->unsignedBigInteger('initial_shares');
            $table->decimal('initial_share_value', 20, 2);
            $table->date('established_on');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('share_valuations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('kind', 20)->default('revaluation');
            $table->decimal('previous_value', 20, 2)->nullable();
            $table->decimal('new_value', 20, 2);
            $table->date('valuation_date');
            $table->unsignedBigInteger('total_shares');
            $table->decimal('previous_total_valuation', 24, 2)->nullable();
            $table->decimal('new_total_valuation', 24, 2);
            $table->text('reason');
            $table->string('status', 20)->default('effective');
            $table->foreignId('performed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
            $table->index(['company_id', 'status', 'valuation_date']);
        });

        Schema::create('share_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 30)->unique();
            $table->string('type', 30);
            $table->foreignId('from_share_holder_id')->nullable()->constrained('share_holders');
            $table->foreignId('to_share_holder_id')->nullable()->constrained('share_holders');
            $table->unsignedBigInteger('shares');
            $table->decimal('share_value', 20, 2);
            $table->decimal('price_per_share', 20, 2)->nullable();
            $table->decimal('total_amount', 24, 2)->nullable();
            $table->string('payment_treatment', 30)->nullable();
            $table->timestamp('transacted_at');
            $table->string('status', 20)->default('completed');
            $table->text('notes')->nullable();
            $table->foreignId('capital_id')->nullable()->constrained('capitals');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries');
            $table->foreignId('reversal_of_id')->nullable()->unique()->constrained('share_transactions');
            $table->string('document_path')->nullable();
            $table->string('document_name', 191)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();
            $table->index(['company_id', 'transacted_at']);
            $table->index(['company_id', 'type']);
        });

        Schema::create('share_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('share_holder_id')->constrained('share_holders');
            $table->unsignedBigInteger('shares')->default(0);
            $table->timestamp('first_acquired_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'share_holder_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('share_positions');
        Schema::dropIfExists('share_transactions');
        Schema::dropIfExists('share_valuations');
        Schema::dropIfExists('share_structures');
    }
};
