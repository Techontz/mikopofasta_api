<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-employee permission overrides (HRM → All Active Staff → Privilege). Effective permissions are the role's
     * permissions plus the granted keys minus the revoked keys (see App\Services\AccessControl::permissionsFor).
     */
    public function up(): void
    {
        Schema::create('employee_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->boolean('granted');
            $table->timestamps();
            $table->unique(['employee_id', 'permission']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_permissions');
    }
};
