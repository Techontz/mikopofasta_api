<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unique Empl/IDs: a unique index per company on employees.employee_number and a per-company counter of the
     * highest sequence ever issued (so numbers of deleted staff are never reused). Existing numbers are never
     * renumbered: when duplicates exist the migration stops and lists them.
     */
    public function up(): void
    {
        $duplicates = DB::table('employees')
            ->whereNotNull('employee_number')
            ->groupBy('company_id', 'employee_number')
            ->havingRaw('COUNT(*) > 1')
            ->selectRaw('company_id, employee_number, COUNT(*) AS total')
            ->get();

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('Duplicate employee numbers must be resolved before the unique index can be added: '.$duplicates->map(fn (object $row): string => "company {$row->company_id} {$row->employee_number} ×{$row->total}")->implode(', '));
        }

        Schema::create('employee_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unique(['company_id', 'employee_number']);
        });

        $reserved = array_values(array_filter(array_column((array) config('demo.accounts', []), 'employee_number')));
        $highest = [];
        foreach (DB::table('employees')->where('employee_number', 'like', 'MK-%')->whereNotIn('employee_number', $reserved)->get(['company_id', 'employee_number']) as $employee) {
            if (preg_match('/^MK-(\d{3,})(\d{4})$/', $employee->employee_number, $matches) === 1) {
                $highest[$employee->company_id] = max($highest[$employee->company_id] ?? 0, (int) $matches[1]);
            }
        }

        foreach ($highest as $companyId => $sequence) {
            DB::table('employee_number_sequences')->insert(['company_id' => $companyId, 'last_sequence' => $sequence, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'employee_number']);
        });

        Schema::dropIfExists('employee_number_sequences');
    }
};
