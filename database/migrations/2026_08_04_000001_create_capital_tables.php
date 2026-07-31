<?php

declare(strict_types=1);

use App\Domain\Treasury\Enums\FloatTransferKind;
use App\Domain\Treasury\Enums\FloatTransferStatus;
use App\Domain\Treasury\Enums\PayMethod;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capital — shareholders, their contributions, and branch float.
 * See docs/modules/capital.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shareholders', function (Blueprint $table): void {
            $table->id();
            $table->string('full_name', 150);
            $table->string('phone', 20)->unique();
            $table->string('email', 150)->unique();
            $table->string('gender', 10);
            $table->date('date_of_birth');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            // Soft-deleted: a contribution must keep pointing at whoever made it.
            $table->softDeletes();
        });

        Schema::create('capital_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('shareholder_id')->constrained('shareholders')->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->enum('pay_method', PayMethod::values());

            // Both optional, and which one is expected depends on pay_method —
            // the legacy screen shows a dash where neither applies.
            $table->string('receipt_no', 60)->nullable();
            $table->string('cheque_no', 60)->nullable();

            /*
             * The entry this contribution posted. Nullable only so the column
             * can be written after LedgerService::post() returns inside the
             * same transaction; a committed row always has one.
             */
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['shareholder_id', 'created_at']);
        });

        /*
         * One table for all three float screens. Every row is the same event —
         * money moving between two ledger accounts — and `kind` records which
         * screen raised it. The account columns are what actually moves; the
         * branch columns are what the screens display.
         */
        Schema::create('float_transfers', function (Blueprint $table): void {
            $table->id();
            $table->enum('kind', FloatTransferKind::values());

            $table->foreignId('from_branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->nullable()->constrained('branches')->restrictOnDelete();

            $table->foreignId('from_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->decimal('amount', 18, 2);
            $table->enum('status', FloatTransferStatus::values())
                ->default(FloatTransferStatus::Pending->value);

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();

            // Null while pending or rejected: money moves on approval, not on
            // request, so a queue of requests never touches the trial balance.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // The three screens filter on exactly these.
            $table->index(['kind', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('float_transfers');
        Schema::dropIfExists('capital_contributions');
        Schema::dropIfExists('shareholders');
    }
};
