<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Face KYC as a record rather than a column.
 *
 * Until now a liveness capture set two things on the customer: a path and a
 * timestamp. Everything the scanner actually measured — how bright the frame
 * was, whether it was sharp, which of the five head positions the customer
 * completed, on which camera — was computed in the browser, used to decide
 * whether to keep the frame, and then thrown away. The record said "verified"
 * and could not say why.
 *
 * That is the wrong shape for a biometric check. A KYC verification is an
 * *event*: it happened at a time, on a device, under an operator, and it
 * either passed or it did not. Two years later the question is never "is this
 * customer verified" — the column already answered that — it is "on what
 * evidence, and who signed it off". So each scan becomes a row.
 *
 * Two consequences follow, and both are deliberate:
 *
 *   1. Scans are never overwritten. The old capture used to be deleted the
 *      moment a new one replaced it, which meant a re-scan destroyed the only
 *      evidence of what the record looked like before. History is the point of
 *      an audit trail; `is_active` marks the current one instead.
 *
 *   2. Every score column here is a measurement, not a label. Nothing writes a
 *      value it did not compute — a scan that could not measure brightness has
 *      no business storing a number for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_scans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            /* passed | failed — the scanner's own verdict on the sequence. */
            $table->string('status', 16);

            /*
             * 0–100, all of them. Percentages rather than the raw physical
             * quantities (mean luma, Laplacian variance, landmark spans)
             * because the raw units are meaningless to the officer reading
             * the profile and would tie the schema to one model's output.
             */
            $table->unsignedTinyInteger('quality_score');
            $table->unsignedTinyInteger('brightness_score');
            $table->unsignedTinyInteger('blur_score');
            $table->unsignedTinyInteger('distance_score');
            $table->unsignedTinyInteger('centering_score');
            $table->unsignedTinyInteger('eyes_open_score');

            /* Which scanner produced this. A threshold change is a change in
               what "passed" meant, so the verdict is only interpretable
               alongside the version that reached it. */
            $table->string('scanner_version', 64);

            $table->boolean('liveness_passed');
            $table->boolean('pose_sequence_completed');

            /*
             * The per-check report: one boolean per thing the scanner looked
             * at, including each of the five head positions. Kept as JSON
             * rather than eleven more columns — it is read as a block, never
             * queried on, and the checklist grows as the scanner does.
             */
            $table->json('checks');

            /* What took the picture, and at what size. Both come from the
               browser's own MediaStream track, not from a guess. */
            $table->string('capture_device')->nullable();
            $table->string('capture_resolution', 32)->nullable();
            $table->unsignedInteger('capture_duration_ms')->nullable();

            /* On the private KYC disk, like every other biometric artefact.
               Never returned; the resource emits a signed URL. */
            $table->string('photo_path');

            /* Why a second scan exists. Blank for the first one. */
            $table->string('reason', 500)->nullable();

            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scanned_at');

            /* Recorded on the row as well as in audit_logs: the audit trail is
               vulnerable to retention policy, and a biometric capture should
               carry its own provenance. */
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();

            /* The current scan. Exactly one per customer, enforced by the
               action rather than the schema — a partial unique index is not
               portable across the engines this runs on. */
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->index(['customer_id', 'scanned_at']);
            $table->index(['customer_id', 'is_active']);
        });

        /*
         * The summary, denormalised onto the customer.
         *
         * The list view, the search results and the loan applicant picker all
         * want to show "verified, 92%" beside a name, and none of them should
         * join a history table to do it. The active `face_scans` row remains
         * the truth; these five columns are its shadow, written only by the
         * action that writes the row.
         */
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreignId('active_face_scan_id')->nullable()->after('photo_path');
            $table->string('face_scan_status', 16)->nullable()->after('active_face_scan_id');
            $table->unsignedTinyInteger('face_scan_quality')->nullable()->after('face_scan_status');
            $table->string('face_scan_version', 64)->nullable()->after('face_scan_quality');
            $table->timestamp('face_scanned_at')->nullable()->after('face_scan_version');
            $table->foreignId('face_scanned_by')->nullable()->after('face_scanned_at')
                ->constrained('users')->nullOnDelete();

            $table->index('face_scan_status');
        });

        /* Added after the table exists, so the two references can point at
           each other without an ordering problem. */
        Schema::table('customers', function (Blueprint $table): void {
            $table->foreign('active_face_scan_id')->references('id')->on('face_scans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropForeign(['active_face_scan_id']);
            $table->dropForeign(['face_scanned_by']);
            $table->dropIndex(['face_scan_status']);
            $table->dropColumn([
                'active_face_scan_id', 'face_scan_status', 'face_scan_quality',
                'face_scan_version', 'face_scanned_at', 'face_scanned_by',
            ]);
        });

        Schema::dropIfExists('face_scans');
    }
};
