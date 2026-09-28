<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chart of accounts + balanced journal entries. Replaces the single-sided ledger_entries table:
     * every money movement is a journal entry whose debit lines equal its credit lines, and
     * corrections are made only by reversal entries (no updates or deletes).
     */
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key');
            $table->string('code', 20);
            $table->string('name');
            $table->string('type');
            $table->boolean('is_system')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'key', 'branch_id', 'bank_account_id', 'employee_id', 'expense_type_id'], 'accounts_scope_unique');
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->unique();
            $table->string('description');
            $table->nullableMorphs('source');
            $table->date('entry_date');
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('reversal_reason')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'entry_date']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->index(['account_id', 'journal_entry_id']);
        });

        Schema::dropIfExists('ledger_entries');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
