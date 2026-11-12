<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eSIMU on top of the Phone Bond tables: Linda registration and QR enrollment before the loan is submitted (with a
 * draft kept while Linda is unreachable), the device's history across loans, replacement requests, lost / stolen /
 * damaged reports, asynchronous Linda commands and de-duplicated Linda webhooks.
 *
 * Permissions: phone_bond.lock now covers managers and the Credit Officer (not Finance); new phone_bond.release,
 * phone_bond.replace_request (Loan Officer) and phone_bond.replace_approve (Credit Officer, Admin; Super Admin has all).
 */
return new class extends Migration
{
    /**
     * @var array<string, list<string>>
     */
    private const GRANTS = [
        'phone_bond.lock' => ['admin', 'branch_manager', 'zone_manager', 'credit_officer'],
        'phone_bond.release' => ['admin', 'credit_officer'],
        'phone_bond.replace_request' => ['loan_officer'],
        'phone_bond.replace_approve' => ['admin', 'credit_officer'],
    ];

    /**
     * @var array<string, list<string>>
     */
    private const REVOKES = [
        'phone_bond.lock' => ['finance'],
    ];

    public function up(): void
    {
        Schema::table('phone_bond_devices', function (Blueprint $table): void {
            $table->index(['company_id', 'imei']);
            $table->dropUnique(['company_id', 'imei']);
            $table->string('linda_customer_id', 100)->nullable()->after('linda_device_id');
            $table->string('linda_status', 30)->nullable()->after('linda_customer_id');
            $table->json('enrollment_payload')->nullable()->after('enrollment_status');
            $table->timestamp('enrollment_expires_at')->nullable()->after('enrollment_payload');
            $table->timestamp('enrolled_at')->nullable()->after('enrollment_expires_at');
            $table->string('registration_error', 500)->nullable()->after('enrolled_at');
            $table->json('application_draft')->nullable()->after('registration_error');
            $table->string('condition', 20)->default('good')->after('status');
            $table->timestamp('inspected_at')->nullable()->after('condition');
            $table->foreignId('inspected_by')->nullable()->after('inspected_at')->constrained('employees')->nullOnDelete();
            $table->index(['company_id', 'customer_id']);
        });

        Schema::table('phone_bond_lock_requests', function (Blueprint $table): void {
            $table->string('idempotency_key', 128)->nullable()->unique()->after('trigger');
            $table->string('command_id', 100)->nullable()->index()->after('idempotency_key');
            $table->string('command_status', 20)->nullable()->after('command_id');
            $table->unsignedSmallInteger('attempts')->default(0)->after('command_status');
            $table->timestamp('last_checked_at')->nullable()->after('attempts');
            $table->timestamp('alerted_at')->nullable()->after('last_checked_at');
            $table->string('previous_lock_state', 20)->nullable()->after('alerted_at');
        });

        Schema::create('phone_bond_device_loans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('phone_bond_devices')->cascadeOnDelete();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->string('linked_by_reason', 30);
            $table->timestamp('linked_at');
            $table->foreignId('linked_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('unlinked_at')->nullable();
            $table->string('unlinked_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['loan_id', 'unlinked_at']);
            $table->index(['device_id', 'unlinked_at']);
        });

        Schema::create('phone_bond_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('loan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('old_device_id')->constrained('phone_bond_devices')->cascadeOnDelete();
            $table->foreignId('new_device_id')->constrained('phone_bond_devices')->cascadeOnDelete();
            $table->string('reason_type', 20);
            $table->string('reason', 500);
            $table->string('status', 20)->default('pending');
            $table->foreignId('requested_by')->constrained('employees');
            $table->foreignId('decided_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decision_note', 500)->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('phone_bond_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('phone_bond_devices')->cascadeOnDelete();
            $table->foreignId('loan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->string('details', 1000);
            $table->date('occurred_on')->nullable();
            $table->foreignId('reported_by')->constrained('employees');
            $table->timestamps();
        });

        Schema::create('linda_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('type', 50);
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        // Every device already linked to a loan starts its history with that link.
        foreach (DB::table('phone_bond_devices')->whereNotNull('loan_id')->get(['id', 'loan_id', 'created_at', 'registered_by', 'status']) as $device) {
            DB::table('phone_bond_device_loans')->insert([
                'device_id' => $device->id,
                'loan_id' => $device->loan_id,
                'linked_by_reason' => 'application',
                'linked_at' => $device->created_at ?? now(),
                'linked_by' => $device->registered_by,
                'unlinked_at' => $device->status === 'closed' ? now() : null,
                'unlinked_reason' => $device->status === 'closed' ? 'Device closed' : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach (self::GRANTS as $permission => $roles) {
            foreach (DB::table('roles')->where('is_system', true)->whereIn('key', $roles)->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission' => $permission]);
            }
        }
        foreach (self::REVOKES as $permission => $roles) {
            DB::table('role_permissions')->where('permission', $permission)
                ->whereIn('role_id', DB::table('roles')->where('is_system', true)->whereIn('key', $roles)->pluck('id'))->delete();
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->whereIn('permission', ['phone_bond.release', 'phone_bond.replace_request', 'phone_bond.replace_approve'])->delete();

        Schema::dropIfExists('linda_webhook_events');
        Schema::dropIfExists('phone_bond_incidents');
        Schema::dropIfExists('phone_bond_replacements');
        Schema::dropIfExists('phone_bond_device_loans');

        Schema::table('phone_bond_lock_requests', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropIndex(['command_id']);
            $table->dropColumn(['idempotency_key', 'command_id', 'command_status', 'attempts', 'last_checked_at', 'alerted_at', 'previous_lock_state']);
        });

        Schema::table('phone_bond_devices', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('inspected_by');
            $table->dropIndex(['company_id', 'customer_id']);
            $table->dropIndex(['company_id', 'imei']);
            $table->dropColumn(['linda_customer_id', 'linda_status', 'enrollment_payload', 'enrollment_expires_at', 'enrolled_at', 'registration_error', 'application_draft', 'condition', 'inspected_at']);
            $table->unique(['company_id', 'imei']);
        });
    }
};
