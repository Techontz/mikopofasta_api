<?php

declare(strict_types=1);

use App\Domain\Treasury\Enums\HqTransactionDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings `hq_account_transfers` up to what the Headquarters Transaction screens
 * actually show. See docs/modules/headquarters.md.
 *
 * The original table was reconstructed from two legacy screens that were both
 * captured with **no rows in them**, so it recorded the columns those screens
 * have (Charger, Staff Name, status) without knowing what any of them contain.
 * The rebuilt frontend then designed the screen properly — `HqTransactionSchema`
 * in types/operations.ts — and it asks for five things the table has no room
 * for: the branch a movement relates to, who requested it, who approved it, the
 * reason given, and whether it adds to the headquarters position or draws it
 * down.
 *
 * The frontend is the source of truth for this rewrite, so the table grows to
 * fit it rather than the screen shrinking to fit the table. Two consequences
 * worth being explicit about:
 *
 *   - `staff_name` and `charger` are kept, not dropped. They are the legacy
 *     columns, and when rows from the old system are eventually imported they
 *     have to land somewhere. `staff_name` is superseded for new records by
 *     `requested_by`, which is a real user rather than free text.
 *   - `direction` is ours. No captured screen shows such a column; it is what
 *     the frontend's `hqBalance()` needs to tell income from expenditure, and
 *     without it the balance card cannot be computed at all.
 *
 * Both account columns become nullable because a movement can have one side.
 * A pot-to-pot transfer — the legacy module's original purpose — has both, and
 * is `internal`: it moves money inside the headquarters float without changing
 * the total, which is why `hqBalance()` counts only `in` and `out`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hq_account_transfers', function (Blueprint $table): void {
            // HQT-0000001. Printed under the branch name on both screens.
            $table->string('reference', 20)->nullable()->after('id');

            $table->foreignId('branch_id')->nullable()->after('to_account_id')
                ->constrained('branches')->restrictOnDelete();

            $table->enum('direction', HqTransactionDirection::values())
                ->default(HqTransactionDirection::Out->value)
                ->after('amount');

            $table->string('reason', 255)->nullable()->after('direction');

            /*
             * Real users, unlike `staff_name`. The frontend prints one of them
             * per screen — Requested By on the queue, Approved By on the
             * approved list — and an approval that cannot name its approver is
             * not an audit trail.
             */
            $table->foreignId('requested_by')->nullable()->after('staff_name')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->after('requested_by')
                ->constrained('users')->nullOnDelete();

            $table->softDeletes();

            $table->index(['status', 'requested_on']);
            $table->index(['direction', 'status']);
        });

        /*
         * A one-sided movement — money arriving at head office, or leaving it —
         * names only the account it landed in or came from. Only a pot-to-pot
         * transfer has both.
         */
        Schema::table('hq_account_transfers', function (Blueprint $table): void {
            $table->unsignedBigInteger('from_account_id')->nullable()->change();
            $table->unsignedBigInteger('to_account_id')->nullable()->change();
        });

        // Backfill any rows already present, so `reference` can be made unique.
        foreach (DB::table('hq_account_transfers')->whereNull('reference')->orderBy('id')->pluck('id') as $id) {
            DB::table('hq_account_transfers')
                ->where('id', $id)
                ->update(['reference' => 'HQT-'.str_pad((string) $id, 7, '0', STR_PAD_LEFT)]);
        }

        Schema::table('hq_account_transfers', function (Blueprint $table): void {
            $table->string('reference', 20)->nullable(false)->unique()->change();
        });
    }

    public function down(): void
    {
        Schema::table('hq_account_transfers', function (Blueprint $table): void {
            $table->dropForeign(['branch_id']);
            $table->dropForeign(['requested_by']);
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['status', 'requested_on']);
            $table->dropIndex(['direction', 'status']);
            $table->dropUnique(['reference']);
            $table->dropColumn([
                'reference', 'branch_id', 'direction', 'reason',
                'requested_by', 'approved_by', 'deleted_at',
            ]);
        });
    }
};
