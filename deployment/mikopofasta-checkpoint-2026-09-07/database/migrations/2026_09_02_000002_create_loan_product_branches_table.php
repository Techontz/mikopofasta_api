<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which branches offer which loan products.
 *
 * WHAT WAS MISSING. The Loan Category screen assigns a product to branches —
 * head office lends one thing, a rural branch another — and there was nowhere
 * to record it. `loans` carries a branch and a product, so the fact could be
 * observed after the event but never stated in advance, which is the wrong way
 * round: an institution decides where a product is offered before anybody
 * borrows it.
 *
 * A PIVOT, NOT A COLUMN. A product is offered at many branches and a branch
 * offers many products, so neither side can hold the other. Unique on the pair,
 * so assigning twice is a no-op rather than a duplicate row.
 *
 * EMPTY MEANS EVERYWHERE. A product with no rows here is offered at every
 * branch — which is what every product that predates this table does, and what
 * an administrator who has never opened the assignment screen expects. The
 * alternative, empty meaning nowhere, would silently withdraw every existing
 * product the moment this migration ran.
 *
 * STRUCTURE ONLY. No row is written; the assignment screen is where they come
 * from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loan_product_branches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('loan_product_id')->constrained('loan_products')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();

            $table->unique(['loan_product_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loan_product_branches');
    }
};
