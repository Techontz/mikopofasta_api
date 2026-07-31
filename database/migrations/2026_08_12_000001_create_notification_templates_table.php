<?php

declare(strict_types=1);

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification templates — Settings → Notification Templates.
 * See docs/modules/administration.md.
 *
 * The one genuinely new table in this module, and the frontend's own type file
 * explains why it exists at all:
 *
 *   "Not in the original 54-table backend schema — the docs describe SMS/email
 *   being sent on specific events (payment received, disbursement failed, etc.)
 *   but not a template management table. A small, clearly-scoped addition so
 *   'Notification Templates' is a real, editable entity rather than hardcoded
 *   message strings."
 *
 * That reasoning is adopted rather than re-litigated. The business documents do
 * specify the messages — REPAYMENT OVERVIEW §1 Step 5 gives one verbatim,
 * "Tumepokea malipo yako ya XXX" — and a message the business has written down
 * belongs in a row somebody can edit, not in a string literal a developer has
 * to be asked to change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);

            /*
             * What sends this message. Enumerated rather than free text: a
             * template keyed to an event nothing raises would look configured
             * and never fire, which is worse than not existing.
             */
            $table->enum('trigger_event', NotificationTriggerEvent::values());
            $table->enum('channel', NotificationChannel::values());

            // SMS has no subject; email does. Nullable rather than empty-string
            // so "this channel has no subject" and "the subject is blank" stay
            // different facts.
            $table->string('subject', 200)->nullable();

            $table->text('body');

            /*
             * Inactive templates are kept, not deleted. Turning a message off
             * for a month is an ordinary thing to want, and deleting the only
             * record of what was being sent is not the way to do it.
             */
            $table->boolean('active')->default(true);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            /*
             * One ACTIVE template per event and channel.
             *
             * Two active SMS templates for `payment_received` would leave the
             * sender picking one arbitrarily — the customer gets whichever row
             * came back first.
             *
             * This uses MySQL's NULL-distinctness deliberately, which is the
             * inverse of what `expense_categories` needed. There the goal was
             * to constrain live rows, and a NULL `deleted_at` made them all
             * look distinct, so the marker had to collapse them onto a shared
             * literal. Here the goal is to constrain live rows and leave every
             * other row unconstrained — so the marker is `'live'` for exactly
             * those and NULL for the rest, and NULLs never collide.
             *
             * Inactive rows fall outside it too: several drafts of the same
             * message may sit alongside the one in use.
             */
            $table->string('uniqueness_marker', 10)
                ->virtualAs("CASE WHEN deleted_at IS NULL AND active = 1 THEN 'live' ELSE NULL END")
                ->nullable();

            $table->unique(['trigger_event', 'channel', 'uniqueness_marker'], 'notification_templates_active_unique');
            $table->index(['trigger_event', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
