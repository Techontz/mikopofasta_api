<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CRM (handwritten notes "CRM: Customer Management System"): calls received/made, SMS sent,
     * follow-up reminders and customer reports/complaints.
     */
    public function up(): void
    {
        Schema::create('crm_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('direction')->default('outgoing');
            $table->string('phone')->nullable();
            $table->string('outcome')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('sms_log_id')->nullable()->constrained()->nullOnDelete();
            $table->date('follow_up_date')->nullable();
            $table->timestamp('follow_up_done_at')->nullable();
            $table->foreignId('follow_up_done_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('follow_up_notes')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'created_at']);
            $table->index(['follow_up_date', 'follow_up_done_at']);
        });

        Schema::create('crm_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('ticket_number')->unique();
            $table->string('category');
            $table->string('channel')->default('call');
            $table->string('priority')->default('normal');
            $table->string('subject');
            $table->text('description');
            $table->string('status')->default('open');
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crm_tickets');
        Schema::dropIfExists('crm_interactions');
    }
};
