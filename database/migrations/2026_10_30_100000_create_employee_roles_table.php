<?php

use App\Http\Middleware\ApplyActiveRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-role support (enhancement §13): employees.role_id stays the primary role; employee_roles holds the further roles
 * an employee is assigned (e.g. FINANCE and HR). The role a session acts as is stored on its token (active_role_id) and
 * revalidated against these assignments on every request ({@see ApplyActiveRole}).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('employees')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'role_id']);
        });

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->unsignedBigInteger('active_role_id')->nullable()->after('abilities');
        });
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropColumn('active_role_id');
        });
        Schema::dropIfExists('employee_roles');
    }
};
