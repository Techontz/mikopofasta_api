<?php

declare(strict_types=1);

use App\Domain\Reversals\Enums\ReversalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a customer salary advance payment reversible through the same desk as
 * a loan repayment.
 *
 * Two halves:
 *
 *   reversal_requests          gains the fifth subject column and admits
 *                              `advance_payment` to its ENUM — adding the case
 *                              to the PHP enum alone would have MySQL truncate
 *                              the value on the first request.
 *
 *   customer_advance_payments  gains `reversed_at`. The row is kept, never
 *                              deleted: it is the transaction history the
 *                              client asked for, and a reversed payment that
 *                              vanished would be indistinguishable from one
 *                              that never happened. Every total skips it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setTypes(ReversalType::values());

        Schema::table('reversal_requests', function (Blueprint $table): void {
            $table->foreignId('customer_advance_payment_id')->nullable()->after('loan_schedule_id')
                ->constrained('customer_advance_payments')->restrictOnDelete()->cascadeOnUpdate();
        });

        Schema::table('customer_advance_payments', function (Blueprint $table): void {
            $table->timestamp('reversed_at')->nullable()->after('journal_entry_id');
            $table->foreignId('reversal_entry_id')->nullable()->after('reversed_at')
                ->constrained('journal_entries')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('customer_advance_payments', function (Blueprint $table): void {
            $table->dropForeign(['reversal_entry_id']);
            $table->dropColumn(['reversed_at', 'reversal_entry_id']);
        });

        Schema::table('reversal_requests', function (Blueprint $table): void {
            $table->dropForeign(['customer_advance_payment_id']);
            $table->dropColumn('customer_advance_payment_id');
        });

        $this->setTypes(array_values(array_filter(
            ReversalType::values(),
            static fn (string $v): bool => $v !== ReversalType::AdvancePayment->value,
        )));
    }

    /** @param list<string> $values */
    private function setTypes(array $values): void
    {
        $list = implode(',', array_map(static fn (string $v): string => "'".addslashes($v)."'", $values));

        DB::statement(
            "ALTER TABLE `reversal_requests` MODIFY COLUMN `reversal_type` ENUM({$list}) NOT NULL DEFAULT 'ledger'",
        );
    }
};
