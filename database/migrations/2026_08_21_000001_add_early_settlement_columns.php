<?php

declare(strict_types=1);

use App\Domain\Loans\Enums\LoanScheduleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Early settlement — client Decision 1, Option B.
 *
 * Two additions:
 *
 * 1. `cancelled` joins `loan_schedules.status`. The column is a database ENUM,
 *    so the PHP case alone is not enough — MySQL would truncate it and the
 *    first settlement would fail with "Data truncated for column 'status'".
 *
 * 2. `loans.interest_waived` records what the lender forgave. The audit log
 *    carries the same figure, but a column is what makes "how much interest did
 *    we forgo to early settlements last quarter" a report rather than a trawl
 *    through JSON.
 *
 * `loans.early_settled_at` distinguishes a loan closed by settlement from one
 * that simply ran to term. Both end `closed`; only one of them cancelled
 * installments, and a customer disputing their statement needs to see which.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setScheduleStatuses(LoanScheduleStatus::values());

        Schema::table('loans', function (Blueprint $table): void {
            $table->decimal('interest_waived', 18, 2)->default('0.00')->after('fee_charged');
            $table->timestamp('early_settled_at')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropColumn(['interest_waived', 'early_settled_at']);
        });

        $this->setScheduleStatuses(array_values(array_filter(
            LoanScheduleStatus::values(),
            static fn (string $v): bool => $v !== 'cancelled',
        )));
    }

    /** @param list<string> $values */
    private function setScheduleStatuses(array $values): void
    {
        $list = implode(',', array_map(static fn (string $v): string => "'".addslashes($v)."'", $values));

        DB::statement("ALTER TABLE `loan_schedules` MODIFY COLUMN `status` ENUM({$list}) NOT NULL DEFAULT 'pending'");
    }
};
