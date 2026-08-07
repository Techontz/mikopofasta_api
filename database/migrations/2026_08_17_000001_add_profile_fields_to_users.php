<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The personal half of an employee's record.
 *
 * Everything already on `users` is organisational — role, branch, zone,
 * status — and is decided by somebody else. None of it is a member of staff's
 * to change, and none of it is what they would go to a profile page to update.
 *
 * These columns are the other half: the details a person maintains about
 * themselves. They are deliberately kept apart from the employment columns on
 * `staff_profiles` (employee number, salary, employment status), which HR owns
 * and which the self-service endpoint refuses to write.
 *
 * No employment field is added here, and none is made writable. A profile page
 * that could change a salary or a branch would be a privilege-escalation
 * surface wearing the clothes of a settings screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /* On the private disk, like every other photograph in this system.
               Never returned as a path; the resource emits a signed URL. */
            $table->string('photo_path')->nullable()->after('email');

            /* Who to call, and how they are related — a relationship without a
               number is not actionable, and a number without a name is not
               either, so the pair is always captured together. */
            $table->string('emergency_contact_name', 120)->nullable()->after('photo_path');
            $table->string('emergency_contact_phone', 20)->nullable()->after('emergency_contact_name');
            $table->string('emergency_contact_relationship', 60)->nullable()->after('emergency_contact_phone');

            $table->string('next_of_kin_name', 120)->nullable()->after('emergency_contact_relationship');
            $table->string('next_of_kin_phone', 20)->nullable()->after('next_of_kin_name');
            $table->string('next_of_kin_relationship', 60)->nullable()->after('next_of_kin_phone');

            $table->string('address', 500)->nullable()->after('next_of_kin_relationship');

            /* Swahili and English are the two languages this business runs in;
               the column is a plain string rather than an enum so adding a
               third is a data change, not a migration. */
            $table->string('preferred_language', 10)->nullable()->after('address');

            /* Which channels this person wants to hear from the system on.
               JSON because it is read as a block and never queried on. */
            $table->json('notification_preferences')->nullable()->after('preferred_language');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'photo_path',
                'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
                'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
                'address', 'preferred_language', 'notification_preferences',
            ]);
        });
    }
};
