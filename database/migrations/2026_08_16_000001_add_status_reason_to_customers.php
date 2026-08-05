<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a customer was suspended.
 *
 * Every other decision about a customer's standing records its justification.
 * Rejecting one writes `rejection_reason`; freezing one writes an
 * `account_freezes` row with a reason, a time and an operator. Suspending one
 * — which stops them borrowing just as surely — wrote a status column and
 * nothing else. A branch could suspend an account and no one could later say
 * on whose authority or on what grounds.
 *
 * These four columns close that gap, on the same pattern `rejection_reason`
 * already uses: the current justification lives on the record where the
 * profile can show it, and the full history is in `audit_logs`, which carries
 * the operator, the branch, the IP and the user agent for every change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            /* Required by the API on both suspension and reactivation — coming
               back is as much a decision as going away. */
            $table->string('status_reason', 500)->nullable()->after('status');
            /* Free-text context: a case number, what the customer was told. */
            $table->string('status_remarks', 1000)->nullable()->after('status_reason');
            $table->timestamp('status_changed_at')->nullable()->after('status_remarks');
            $table->foreignId('status_changed_by')->nullable()->after('status_changed_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['status_changed_by']);
            $table->dropColumn(['status_reason', 'status_remarks', 'status_changed_at', 'status_changed_by']);
        });
    }
};
