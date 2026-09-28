<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Loan agreement flow: the system generates the agreement once the branch manager approves, the customer fills
     * and signs it, and the signed copy is uploaded before the credit officer can approve. Who uploaded it and when is
     * kept on the loan (agreement_file already exists).
     */
    public function up(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (! Schema::hasColumn('loans', 'agreement_uploaded_at')) {
                $table->dateTime('agreement_uploaded_at')->nullable()->after('agreement_file');
            }
            if (! Schema::hasColumn('loans', 'agreement_uploaded_by')) {
                $table->foreignId('agreement_uploaded_by')->nullable()->after('agreement_uploaded_at')->constrained('employees')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table) {
            if (Schema::hasColumn('loans', 'agreement_uploaded_by')) {
                $table->dropConstrainedForeignId('agreement_uploaded_by');
            }
            if (Schema::hasColumn('loans', 'agreement_uploaded_at')) {
                $table->dropColumn('agreement_uploaded_at');
            }
        });
    }
};
