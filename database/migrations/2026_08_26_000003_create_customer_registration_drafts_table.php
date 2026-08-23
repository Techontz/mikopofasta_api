<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An unfinished registration, held by the server rather than the browser.
 *
 * WHAT WAS WRONG. The wizard autosaved to `localStorage` under a single fixed
 * key. Three consequences, all of them real:
 *
 *   1. One draft per BROWSER, not per customer. An officer interrupted while
 *      registering Amina, who then started registering Baraka, overwrote
 *      Amina's form and had no way back to it.
 *   2. Nothing survived the device. Clearing site data, a different machine, a
 *      supervisor picking the file up — the work was gone, and there was no
 *      record it had ever existed.
 *   3. The office could not see the queue. Half-finished registrations are
 *      operationally important: they are customers standing at a counter who
 *      did not get an account. Nobody could count them.
 *
 * So a draft is a row. It is deliberately NOT a Customer: a customer number is
 * issued once and must mean something, and a table of half-created customers
 * would pollute every list, every count and every report in the system for the
 * sake of a form somebody abandoned. The draft holds the wizard payload as
 * JSON and nothing else claims to be true about it.
 *
 * `payload` is untyped on purpose. It mirrors the wizard's own shape, which
 * changes as the form does, and validating it here would mean two schemas that
 * must agree — the draft is never trusted: resuming replays it through the
 * ordinary form, and submitting replays it through RegisterCustomerRequest.
 * Nothing reaches the database from a draft without passing the same rules a
 * typed-in registration passes.
 *
 * The browser autosave is kept as well. It fires on every keystroke and this
 * does not; between two server saves the local copy is what survives a refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_registration_drafts', function (Blueprint $table): void {
            $table->id();

            /*
             * Whose draft it is. Branch too, so a supervisor can see the
             * branch's unfinished work without reading every officer's — and
             * so §13 branch scoping applies to drafts exactly as it does to
             * the customers they will become.
             */
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            /*
             * A label for the list. Derived from the payload's name and phone
             * when it is saved, because a draft with no customer yet still has
             * to be findable — "Untitled draft (3)" is not something an officer
             * can pick between.
             */
            $table->string('label', 160);
            $table->string('phone', 20)->nullable()->index();

            $table->json('payload');
            /* Which step to reopen on. Not derived from the payload: the
               officer may have stepped back to check something, and dropping
               them somewhere else on resume is the small betrayal that makes
               people stop trusting a save button. */
            $table->unsignedTinyInteger('step')->default(0);

            /*
             * Set when the draft becomes a real customer. The row is kept
             * rather than deleted so "this registration took four sittings
             * across two days" stays answerable, and so a double submit
             * resolves to the customer already created instead of a second one.
             */
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();

            /* The two questions asked of this table: "what have I got open?"
               and "what has this branch got open?" */
            $table->index(['created_by', 'submitted_at']);
            $table->index(['branch_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_registration_drafts');
    }
};
