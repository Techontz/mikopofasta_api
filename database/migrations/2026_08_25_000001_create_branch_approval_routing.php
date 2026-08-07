<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-branch approval routing — client decision D4, meeting note N2.
 *
 *     "Zone (Mtu wa kuview na kucomment) — depending on branches given."
 *
 * A branch that belongs to a zone is reviewed by that zone. A branch that does
 * not routes straight to Head Office Credit. Until now the chain was global:
 * `is_active` switched a stage on or off for the entire institution, which
 * cannot express "zone review, but only for the branches that have one".
 *
 * ## Three pieces, and why each is separate
 *
 * **`loan_approval_stages.requires_branch_zone`** — the RULE, as data. It says
 * "this stage applies only where the branch belongs to a zone". Without it,
 * knowing which stage is the zone stage would mean testing for the string
 * 'ZONE_MANAGER', which is exactly the hardcoding D4 forbids and would break
 * the day somebody renamed or reordered the chain.
 *
 * **`branch_approval_routes`** — the OVERRIDE, per branch. The rule above is a
 * sensible default, not a law: an administrator may want a zoned branch to skip
 * zone review, or an unzoned one to be reviewed anyway. A row here decides that
 * branch's answer for that stage; no row means "use the default".
 *
 * **`loan_approval_routes`** — the SNAPSHOT, per loan. This is the one that
 * matters most, and it exists because of an explicit instruction: *"Existing
 * loans already in progress must continue following the route they were assigned
 * when created. Do not silently reroute loans that are already in the
 * workflow."*
 *
 * Configuration is a live table. Without a snapshot, moving a branch into a zone
 * on Tuesday would insert a zone review into every application raised on Monday
 * that had already cleared its branch manager — some of them would jump
 * backwards, and an approver would be asked to decide a file that had already
 * passed them. Taking the route at application time makes the chain a property
 * of the agreement, like the interest rate snapshot beside it.
 *
 * ## Why the snapshot is rows rather than a flag
 *
 * A boolean `zone_review_required` would answer today's question and nothing
 * else. The chain is configurable and will gain stages; a route recorded as the
 * ordered list of stages the loan actually entered stays correct however the
 * configuration changes afterwards, and reads back as exactly what it is — the
 * chain this loan is walking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loan_approval_stages', function (Blueprint $table): void {
            $table->boolean('requires_branch_zone')->default(false)->after('requires_mandate_before');
        });

        /*
         * The zone stage as seeded today. Set here as well as in the seeder so
         * an installation whose seeders have already run is migrated into the
         * behaviour D4 describes, rather than keeping zone review switched on
         * for branches that have no zone.
         */
        DB::table('loan_approval_stages')
            ->where('code', 'ZONE_MANAGER')
            ->update(['requires_branch_zone' => true]);

        Schema::create('branch_approval_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_approval_stage_id')->constrained()->cascadeOnDelete();

            /*
             * True forces the stage in, false forces it out. There is no third
             * state: "use the default" is the absence of a row, which keeps the
             * table small and makes an override visible as a deliberate act
             * rather than something to be inferred from a value.
             */
            $table->boolean('is_required');
            $table->timestamps();

            $table->unique(['branch_id', 'loan_approval_stage_id'], 'branch_route_unique');
        });

        Schema::create('loan_approval_routes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('loan_approval_stage_id')->constrained()->cascadeOnDelete();

            /*
             * Copied from the stage rather than joined at read time. The
             * snapshot has to survive the stage being reordered afterwards —
             * that is the whole point of taking one.
             */
            $table->unsignedInteger('sequence');
            $table->timestamps();

            $table->unique(['loan_id', 'loan_approval_stage_id'], 'loan_route_unique');
            $table->index(['loan_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_approval_routes');
        Schema::dropIfExists('branch_approval_routes');

        Schema::table('loan_approval_stages', function (Blueprint $table): void {
            $table->dropColumn('requires_branch_zone');
        });
    }
};
