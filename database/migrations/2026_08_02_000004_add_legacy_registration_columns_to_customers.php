<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fields the legacy registration form collects that this system did not.
 *
 * Taken field-by-field off the three legacy screens, in their order. Nothing
 * here is invented: every column exists because the form being reproduced asks
 * for it, and the instruction is that nothing entered may silently disappear.
 *
 * PAYMENT CARD DATA IS NOT STORED. The legacy step 3 has "Card number" and an
 * expiry. Keeping a PAN — even briefly, even encrypted — puts the whole
 * application in PCI-DSS scope, with the audit, key-management and network
 * obligations that follow. So the field is accepted and immediately reduced:
 * only the last four digits survive, which is enough to recognise a card on a
 * statement and useless to anyone who steals the database. The expiry is kept
 * because it identifies nothing on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // ------------------------------------------- step 1: basic information
            /*
             * The officer who owns this relationship — "Employee" on the legacy
             * form. A staff member, so it points at users rather than repeating
             * their name as text.
             */
            $table->foreignId('employee_id')->nullable()->after('branch_id')
                ->constrained('users')->nullOnDelete();
            /*
             * Loan Type and Types of customer, both admin-managed master data.
             * `restrictOnDelete` because a list entry that customers reference
             * must be disabled, not removed out from under them.
             */
            $table->foreignId('loan_type_id')->nullable()->after('employee_id')
                ->constrained('loan_types')->restrictOnDelete();
            $table->foreignId('customer_type_id')->nullable()->after('loan_type_id')
                ->constrained('customer_types')->restrictOnDelete();

            // ------------------------------------------- step 2: additional detail
            $table->string('nickname', 80)->nullable()->after('last_name');
            $table->foreignId('account_type_id')->nullable()->after('customer_type_id')
                ->constrained('account_types')->restrictOnDelete();
            $table->foreignId('work_type_id')->nullable()->after('account_type_id')
                ->constrained('work_types')->restrictOnDelete();
            $table->string('department', 120)->nullable()->after('employer');
            $table->string('council_number', 60)->nullable()->after('department');
            $table->string('place_of_employment', 150)->nullable()->after('council_number');
            $table->date('retirement_date')->nullable()->after('place_of_employment');
            $table->unsignedSmallInteger('dependents_count')->nullable()->after('retirement_date');
            /*
             * Basic salary and take-home are distinct figures and both matter:
             * affordability is assessed on take-home, while basic salary is what
             * a statutory deduction is computed against. Minor units, like every
             * other amount in this system.
             */
            $table->unsignedBigInteger('basic_salary')->nullable()->after('dependents_count');
            $table->unsignedBigInteger('take_home')->nullable()->after('basic_salary');
            $table->string('check_number', 60)->nullable()->after('take_home');

            // -------------------------------- step 3: passport size & bank detail
            /*
             * The legacy screen takes one field for "NIDA / Voter ID / Driver`s
             * Licence number" — whichever the customer produced. Split, because
             * a single column cannot answer "which document is this?", and a
             * KYC record that cannot name its own evidence is not evidence.
             * `national_id_number` already exists from the previous migration.
             */
            $table->string('voter_id_number', 40)->nullable()->after('national_id_number');
            $table->string('driver_licence_number', 40)->nullable()->after('voter_id_number');
            $table->string('work_id_number', 60)->nullable()->after('driver_licence_number');

            /* See the class comment: last four only, never the PAN. */
            $table->string('card_last_four', 4)->nullable()->after('account_number');
            $table->unsignedTinyInteger('card_expiry_month')->nullable()->after('card_last_four');
            $table->unsignedSmallInteger('card_expiry_year')->nullable()->after('card_expiry_month');

            /* The remaining money-related lists, now master data. */
            $table->foreignId('bank_id')->nullable()->after('card_expiry_year')
                ->constrained('banks')->restrictOnDelete();
            $table->foreignId('mobile_money_provider_id')->nullable()->after('bank_id')
                ->constrained('mobile_money_providers')->restrictOnDelete();
            $table->foreignId('occupation_id')->nullable()->after('occupation')
                ->constrained('occupations')->restrictOnDelete();
            $table->foreignId('employment_type_id')->nullable()->after('employment_type')
                ->constrained('employment_types')->restrictOnDelete();
            $table->foreignId('marital_status_id')->nullable()->after('marital_status')
                ->constrained('marital_statuses')->restrictOnDelete();

            $table->index('work_id_number');
            $table->index('check_number');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropIndex(['work_id_number']);
            $table->dropIndex(['check_number']);

            foreach ([
                'employee_id', 'loan_type_id', 'customer_type_id', 'account_type_id',
                'work_type_id', 'bank_id', 'mobile_money_provider_id', 'occupation_id',
                'employment_type_id', 'marital_status_id',
            ] as $fk) {
                $table->dropConstrainedForeignId($fk);
            }

            $table->dropColumn([
                'nickname', 'department', 'council_number', 'place_of_employment',
                'retirement_date', 'dependents_count', 'basic_salary', 'take_home',
                'check_number', 'voter_id_number', 'driver_licence_number',
                'work_id_number', 'card_last_four', 'card_expiry_month', 'card_expiry_year',
            ]);
        });
    }
};
