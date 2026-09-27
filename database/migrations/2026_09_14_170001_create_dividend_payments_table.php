<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dividend declarations keep the full breakdown and a snapshot of ownership; entitlements are paid in one or more
     * payments (partial payments), each posted Dr DIVIDEND ACCOUNT / Cr COMPANY ACCOUNT or bank and reversible.
     *
     *  - dividend_declarations: total_shares and as_of_date (share-register snapshot date), profit_source
     *    (period_close | profit_account), declared_at.
     *  - dividend_allocations: contribution_total (information only), paid_amount; status becomes
     *    unpaid / partially_paid / paid (legacy "pending" → "unpaid").
     *  - dividend_payments: one row per payment with its journal entry, idempotency key and reversal link.
     *
     * Existing rows are backfilled by 2026_09_14_170002_backfill_dividend_payments.
     */
    public function up(): void
    {
        Schema::table('dividend_declarations', function (Blueprint $table) {
            $table->unsignedBigInteger('total_shares')->nullable()->after('dividend_amount');
            $table->date('as_of_date')->nullable()->after('total_shares');
            $table->string('profit_source', 30)->nullable()->after('as_of_date');
            $table->timestamp('declared_at')->nullable()->after('declared_by');
        });

        Schema::table('dividend_allocations', function (Blueprint $table) {
            $table->decimal('contribution_total', 15, 2)->nullable()->after('share_percent');
            $table->decimal('paid_amount', 15, 2)->default(0)->after('amount');
            $table->index(['dividend_declaration_id', 'status']);
        });

        Schema::create('dividend_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('dividend_allocation_id')->constrained()->restrictOnDelete();
            $table->foreignId('share_holder_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('pay_method', 10);
            $table->string('source_account', 40);
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('paid_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->string('status', 20)->default('posted');
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->foreignId('reversed_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'paid_at']);
            $table->index(['dividend_allocation_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividend_payments');

        DB::table('dividend_allocations')->where('status', 'unpaid')->update(['status' => 'pending']);
        DB::table('dividend_allocations')->where('status', 'partially_paid')->update(['status' => 'pending']);

        Schema::table('dividend_allocations', function (Blueprint $table) {
            $table->dropIndex(['dividend_declaration_id', 'status']);
            $table->dropColumn(['contribution_total', 'paid_amount']);
        });

        Schema::table('dividend_declarations', function (Blueprint $table) {
            $table->dropColumn(['total_shares', 'as_of_date', 'profit_source', 'declared_at']);
        });
    }
};
