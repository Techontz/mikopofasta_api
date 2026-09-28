<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_code')->nullable()->unique();
            $table->string('first_name');
            $table->string('middle_name');
            $table->string('last_name');
            $table->string('gender');
            $table->date('date_of_birth');
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('phone');
            $table->string('work_status');
            $table->string('customer_type')->nullable();
            $table->foreignId('region_id')->nullable()->constrained();
            $table->string('district');
            $table->string('ward');
            $table->string('street');
            $table->string('nickname')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('account_type')->nullable();
            $table->string('business_type')->nullable();
            $table->string('place_of_business')->nullable();
            $table->unsignedSmallInteger('dependents')->nullable();
            $table->decimal('monthly_income', 15, 2)->nullable();
            $table->string('passport_photo')->nullable();
            $table->string('id_number')->nullable();
            $table->string('id_attachment')->nullable();
            $table->string('check_number')->nullable();
            $table->string('account_number')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_password')->nullable();
            $table->string('kyc_status')->default('pending');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('registration_step')->default(1);
            $table->boolean('is_marked')->default(false);
            $table->timestamps();
        });

        Schema::create('guarantors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('loan_id')->nullable()->index();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('phone');
            $table->string('gender')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('id_number')->nullable();
            $table->string('relationship');
            $table->foreignId('region_id')->nullable()->constrained();
            $table->string('district')->nullable();
            $table->string('ward')->nullable();
            $table->string('street')->nullable();
            $table->string('photo')->nullable();
            $table->timestamps();
        });

        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->text('message');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('guarantors');
        Schema::dropIfExists('customers');
    }
};
