<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loan lifecycle (Documents: LOAN PROCESS OVERVIEW): reference number after credit approval,
     * telco verification, disbursement tracking, DPD, top-up link, closure and freeze period.
     */
    public function up(): void
    {
        $columns = [
            'reference_number' => fn (Blueprint $table) => $table->string('reference_number', 30)->nullable()->unique()->after('loan_number'),
            'decision_reason' => fn (Blueprint $table) => $table->text('decision_reason')->nullable(),
            'telco_name' => fn (Blueprint $table) => $table->string('telco_name')->nullable(),
            'telco_matched' => fn (Blueprint $table) => $table->boolean('telco_matched')->nullable(),
            'telco_verified_at' => fn (Blueprint $table) => $table->timestamp('telco_verified_at')->nullable(),
            'disbursement_channel' => fn (Blueprint $table) => $table->string('disbursement_channel', 20)->nullable(),
            'disbursement_attempts' => fn (Blueprint $table) => $table->unsignedTinyInteger('disbursement_attempts')->default(0),
            'disbursed_at' => fn (Blueprint $table) => $table->timestamp('disbursed_at')->nullable(),
            'days_past_due' => fn (Blueprint $table) => $table->unsignedInteger('days_past_due')->default(0),
            'topup_of_loan_id' => fn (Blueprint $table) => $table->foreignId('topup_of_loan_id')->nullable()->constrained('loans')->nullOnDelete(),
            'closed_at' => fn (Blueprint $table) => $table->timestamp('closed_at')->nullable(),
            'frozen_until' => fn (Blueprint $table) => $table->date('frozen_until')->nullable(),
        ];

        foreach ($columns as $name => $definition) {
            if (! Schema::hasColumn('loans', $name)) {
                Schema::table('loans', fn (Blueprint $table) => $definition($table));
            }
        }

        if (! Schema::hasColumn('companies', 'loan_freeze_days')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->unsignedInteger('loan_freeze_days')->default(0);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('topup_of_loan_id');
            $table->dropUnique(['reference_number']);
            $table->dropColumn([
                'reference_number', 'decision_reason', 'telco_name', 'telco_matched', 'telco_verified_at', 'disbursement_channel',
                'disbursement_attempts', 'disbursed_at', 'days_past_due', 'closed_at', 'frozen_until',
            ]);
        });

        if (Schema::hasColumn('companies', 'loan_freeze_days')) {
            Schema::table('companies', function (Blueprint $table) {
                $table->dropColumn('loan_freeze_days');
            });
        }
    }
};
