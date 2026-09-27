<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zones group branches for zone managers; roles carry editable permission sets.
     */
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('company_id')->constrained()->nullOnDelete();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->unique(['role_id', 'permission']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->after('role_id')->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropConstrainedForeignId('role_id');
        });
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::table('branches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
        });
        Schema::dropIfExists('zones');
    }
};
