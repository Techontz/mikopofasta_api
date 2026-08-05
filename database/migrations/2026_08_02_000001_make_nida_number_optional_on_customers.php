<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Makes the National ID optional, so a customer can be registered by hand.
 *
 * WHY. `nida_number` was NOT NULL and the registration request required it
 * along with three verification timestamps — nida, otp and face. That contract
 * only holds if there is a NIDA registry to look a person up in. There is not:
 * the integration does not exist, and what stood in for it was
 * `NidaRegistry`, a service that invented a name, a date of birth and a gender
 * from a hash of whatever number was typed. Every customer registered through
 * that flow carried a fabricated identity and three timestamps vouching for
 * checks that never ran.
 *
 * Until the real registry is available, customers are registered manually: the
 * officer enters what the customer tells them, and the National ID is one
 * optional field among the rest. That is honest — an unverified record that
 * says it is unverified — where the simulator was not.
 *
 * WHAT THIS DOES NOT CHANGE. The column stays UNIQUE, so two customers still
 * cannot share a National ID; SQL permits any number of NULLs under a unique
 * index, which is exactly the "optional but unique when present" rule wanted
 * here. The three verification timestamps were already nullable — only their
 * validation rules insisted on them — so nothing about them changes at the
 * database level. When the NIDA integration ships, a `NidaIdentityProvider`
 * fills all four again and nothing here has to be undone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->string('nida_number', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        /*
         * Reversing this needs every NULL filled first — the column is unique,
         * so they cannot all become the same placeholder. Failing loudly beats
         * a rollback that silently drops or collides manually-registered
         * customers.
         */
        $orphans = DB::table('customers')->whereNull('nida_number')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "Cannot reverse: {$orphans} customer(s) have no National ID. "
                .'Give each one a value before rolling back.',
            );
        }

        Schema::table('customers', function (Blueprint $table): void {
            $table->string('nida_number', 30)->nullable(false)->change();
        });
    }
};
