<?php

declare(strict_types=1);

use App\Domain\Customers\Enums\GroupRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group officers and the meeting schedule.
 *
 * `groups` already carried a single `leader_customer_id`, which cannot express
 * the committee a village banking group actually elects — a chairperson, a
 * secretary who keeps the register, and a treasurer who counts the cash. Those
 * are three distinct people and separating them is the group's own internal
 * control, so the role belongs on the membership row rather than as three more
 * columns on the group.
 *
 * `leader_customer_id` stays. It is the group's public representative and is
 * already referenced elsewhere; the membership role is what the officer list is
 * read from. A partial unique index keeps at most one holder of each office per
 * group — plain members are unconstrained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_members', function (Blueprint $table): void {
            $table->enum('role', GroupRole::values())
                ->default(GroupRole::Member->value)
                ->after('customer_id');
        });

        Schema::table('groups', function (Blueprint $table): void {
            // Meetings are the group's collection rhythm: a day of the week and
            // the hour they sit. Nullable — a newly formed group has not set one.
            $table->unsignedTinyInteger('meeting_day')->nullable()->after('status');
            $table->time('meeting_time')->nullable()->after('meeting_day');
        });

        /*
         * One chairperson, one secretary, one treasurer per group. Expressed as
         * a partial index rather than in application code so a race between two
         * concurrent appointments cannot leave a group with two treasurers.
         *
         * MySQL has no partial indexes, so the guard is a generated column that
         * is NULL for ordinary members — NULLs do not collide in a unique index.
         */
        Schema::table('group_members', function (Blueprint $table): void {
            $table->string('officer_role', 20)
                ->nullable()
                ->storedAs("CASE WHEN role IN ('leader','secretary','treasurer') THEN role ELSE NULL END")
                ->after('role');

            $table->unique(['group_id', 'officer_role'], 'group_members_one_officer_per_role');
        });
    }

    public function down(): void
    {
        Schema::table('group_members', function (Blueprint $table): void {
            $table->dropUnique('group_members_one_officer_per_role');
            $table->dropColumn(['officer_role', 'role']);
        });

        Schema::table('groups', function (Blueprint $table): void {
            $table->dropColumn(['meeting_day', 'meeting_time']);
        });
    }
};
