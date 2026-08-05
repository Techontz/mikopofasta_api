<?php

declare(strict_types=1);

use App\Domain\Loans\Enums\LoanApprovalDecision;
use App\Domain\Loans\Enums\LoanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The loan approval chain the client specified:
 *
 *     Loan Officer → Branch Manager → Zone Manager → Head Office Credit → Disbursement
 *
 * with Approve, Reject, Return for Modification and Hold available at every
 * stage, and every action audited.
 *
 * ## Why the chain is a table
 *
 * A four-step chain written into a `match` looks perfectly reasonable until the
 * business wants zone sign-off skipped for loans under 500,000, or a region
 * added, or the order swapped — each of which then costs a deploy. The stages
 * are rows, ordered by `sequence` and switchable by `is_active`, so a chain
 * change is a configuration change.
 *
 * The honest limit, stated rather than glossed over: each stage names the
 * `loans.status` a loan waits in, and those statuses are a PHP enum the
 * frontend mirrors. Reordering, deactivating and re-permissioning stages are
 * pure data. Introducing a genuinely NEW kind of stage still needs a status
 * case in both repositories — that is the one code touchpoint, and pretending
 * otherwise would be worse than saying so.
 *
 * ## Why decisions get their own table
 *
 * `loan_status_history` already records every transition, but a transition is
 * not a decision. A hold and a return both leave `pending_zone_approval`, an
 * approval at stage 2 and an approval at stage 3 look alike from the status
 * alone, and neither records WHICH stage an approver was acting for. Questions
 * the business will actually ask — how long loans sit at zone, who returns the
 * most applications, which stage rejects most — are queries against this table
 * and archaeology without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_approval_stages', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();

            /*
             * Position in the chain. Gapped by tens when seeded so a stage can
             * be inserted between two others without renumbering the rest.
             */
            $table->unsignedInteger('sequence')->unique();

            // The status a loan waits in while this stage is deciding.
            $table->enum('loan_status', LoanStatus::values());

            /*
             * The permission an approver must hold. A permission rather than a
             * role, because roles→permissions is already an administrator-
             * managed matrix — naming a role here would put a second, competing
             * authority on "who may approve" into the schema.
             */
            $table->string('required_permission', 100);

            /*
             * True on the stage that must not begin until the bank e-mandate is
             * live. Keeps the §10 conditional branch in data instead of an
             * `if` on a status name in the workflow.
             */
            $table->boolean('requires_mandate_before')->default(false);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sequence']);
        });

        Schema::create('loan_approval_decisions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('loan_id')->constrained('loans')->cascadeOnDelete();

            /*
             * Nullable, and restrictOnDelete rather than cascade: a stage that
             * is later removed must not erase the decisions taken under it. The
             * history of who approved what is not the configuration's to
             * delete.
             */
            $table->foreignId('loan_approval_stage_id')->nullable()
                ->constrained('loan_approval_stages')->restrictOnDelete();

            // Denormalised so the trail still reads correctly if a stage is
            // renamed or retired years later.
            $table->string('stage_code', 40);
            $table->string('stage_name', 80);

            $table->enum('decision', LoanApprovalDecision::values());

            $table->enum('from_status', LoanStatus::values());
            $table->enum('to_status', LoanStatus::values());

            /*
             * Required for everything except a plain approval — a rejection, a
             * return or a hold that nobody has to explain is a decision the
             * applicant cannot answer. Enforced in the request, nullable here
             * because an approval genuinely has nothing to say.
             */
            $table->text('reason')->nullable();

            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');

            $table->index(['loan_id', 'created_at']);
            $table->index(['stage_code', 'decision']);
            $table->index('decided_by');
        });

        Schema::table('loans', function (Blueprint $table): void {
            /*
             * Where the loan currently sits in the chain. Denormalises what the
             * status already implies, so the pending queues can be filtered and
             * counted per stage without mapping statuses back to stages on
             * every query.
             */
            $table->foreignId('approval_stage_id')->nullable()->after('status')
                ->constrained('loan_approval_stages')->nullOnDelete();

            /*
             * Where a held loan goes back to when it is released.
             *
             * Held on the loan rather than re-derived from the decision trail:
             * releasing must land the loan exactly where it was, and a rule
             * that reconstructed "exactly where it was" from history would be a
             * second implementation of the chain, free to disagree with the
             * first.
             */
            $table->enum('hold_resume_status', LoanStatus::values())->nullable()->after('approval_stage_id');

            $table->index('approval_stage_id');
        });
    }

    public function down(): void
    {
        Schema::table('loans', function (Blueprint $table): void {
            $table->dropForeign(['approval_stage_id']);
            $table->dropColumn(['approval_stage_id', 'hold_resume_status']);
        });

        Schema::dropIfExists('loan_approval_decisions');
        Schema::dropIfExists('loan_approval_stages');
    }
};
