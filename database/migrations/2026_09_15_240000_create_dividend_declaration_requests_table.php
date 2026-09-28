<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * C1 + rule 6 (maker/checker): a dividend declaration is REQUESTED (pending, no journal) and posted only when a different
     * authorised user approves it. The request keeps the figures the initiator saw; approval re-validates them. `pending_key`
     * ("<company>:<YYYY-MM>") is set only while the request is pending, so the unique index allows one standing request per
     * company and month while rejected/approved requests stay listed. Existing declarations are untouched (they have no request).
     */
    public function up(): void
    {
        Schema::create('dividend_declaration_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period');
            $table->string('status', 20)->default('pending')->index();
            $table->string('pending_key', 40)->nullable()->unique();
            $table->decimal('profit_amount', 15, 2);
            $table->decimal('distributable_profit', 15, 2)->nullable();
            $table->decimal('commission_amount', 15, 2)->nullable();
            $table->decimal('dividend_percent', 5, 2);
            $table->decimal('dividend_amount', 15, 2);
            $table->decimal('reinvest_percent', 5, 2);
            $table->decimal('reinvest_amount', 15, 2);
            $table->foreignId('requested_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('requested_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('dividend_declaration_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'period']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dividend_declaration_requests');
    }
};
