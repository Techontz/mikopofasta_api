<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * E-Mandate / Direct Debit as a loan method (enhancement §4): the loan category's loan method (normal, e_mandate,
 * phone_bond; requires_mandate kept in step), mandate tracking (provider, channel, type, cancellation), collection batches
 * and collections. A collection is recorded once per provider payment reference and allocated through the existing
 * repayment rules; Full / Partial follow the amount allocated against what was due, not the provider's status.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_categories', function (Blueprint $table): void {
            $table->string('loan_method', 20)->default('normal')->after('requires_mandate');
        });
        DB::table('loan_categories')->where('requires_mandate', true)->update(['loan_method' => 'e_mandate']);

        Schema::table('loan_mandates', function (Blueprint $table): void {
            $table->string('provider', 60)->nullable()->after('bank_name');
            $table->string('channel', 40)->nullable()->after('provider');
            $table->string('mandate_type', 40)->nullable()->after('channel');
            $table->string('cancellation_status', 20)->nullable()->after('activated_at');
            $table->timestamp('cancellation_requested_at')->nullable()->after('cancellation_status');
            $table->foreignId('cancellation_requested_by')->nullable()->after('cancellation_requested_at')->constrained('employees')->nullOnDelete();
            $table->string('cancellation_reference', 100)->nullable()->after('cancellation_requested_by');
            $table->timestamp('cancelled_at')->nullable()->after('cancellation_reference');
        });

        Schema::create('mandate_collection_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 60)->unique();
            $table->string('provider', 60);
            $table->string('channel', 40)->nullable();
            $table->string('source', 20);
            $table->date('collection_date');
            $table->unsignedInteger('record_count')->default(0);
            $table->decimal('expected_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('mandate_collections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('mandate_collection_batches')->nullOnDelete();
            $table->foreignId('loan_mandate_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 60);
            $table->string('channel', 40)->nullable();
            $table->string('mandate_type', 40)->nullable();
            $table->string('mandate_reference', 100)->nullable();
            $table->string('payment_reference', 100);
            $table->string('raw_status', 60);
            $table->string('payment_status', 20);
            $table->decimal('expected_amount', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->decimal('allocated_amount', 15, 2)->default(0);
            $table->date('collection_date');
            $table->string('remarks', 500)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'provider', 'payment_reference']);
            $table->index(['company_id', 'collection_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mandate_collections');
        Schema::dropIfExists('mandate_collection_batches');
        Schema::table('loan_mandates', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cancellation_requested_by');
            $table->dropColumn(['provider', 'channel', 'mandate_type', 'cancellation_status', 'cancellation_requested_at', 'cancellation_reference', 'cancelled_at']);
        });
        Schema::table('loan_categories', function (Blueprint $table): void {
            $table->dropColumn('loan_method');
        });
    }
};
