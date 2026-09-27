<?php

namespace Tests\Feature\Api\Accounting;

use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Branch;
use App\Models\Capital;
use App\Models\Company;
use App\Models\Concerns\Auditable;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\Employee;
use App\Models\ExpenseRequest;
use App\Models\InterestFormula;
use App\Models\Loan;
use App\Models\LoanCategory;
use App\Models\Role;
use App\Models\ShareHolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuditTrailApiTest extends TestCase
{
    use AccountingTestHelpers, RefreshDatabase;

    public function test_changes_made_through_the_api_record_the_sanctum_employee_and_before_after_values(): void
    {
        $admin = $this->signInAdmin();
        $regionId = DB::table('regions')->insertGetId(['name' => 'DAR ES SALAAM']);
        $branch = $admin->branch;
        $token = $admin->createToken('test')->plainTextToken;
        auth()->forgetGuards();

        $this->withToken($token)->putJson("/api/v1/settings/branches/{$branch->id}", [
            'blanch_name' => 'KARIAKOO', 'region_id' => $regionId, 'blanch_no' => '0711000000', 'branch_type' => 'main',
        ])->assertOk();

        $log = AuditLog::where('action', 'Branch.updated')->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->employee_id);
        $this->assertSame('KARIAKOO', $log->after['name']);
        $this->assertSame($branch->name, $log->before['name']);
        $this->assertSame($admin->company_id, $log->company_id);

        $this->withToken($token)->getJson('/api/v1/accounting/audit?model=Branch&action=updated')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee', $admin->full_name)
            ->assertJsonPath('data.0.model', 'Branch')
            ->assertJsonPath('data.0.event', 'updated')
            ->assertJsonFragment(['field' => 'name', 'before' => $branch->name, 'after' => 'KARIAKOO']);
    }

    public function test_money_and_config_models_are_audited(): void
    {
        $admin = $this->signInAdmin();

        foreach ([Branch::class, LoanCategory::class, Customer::class, Loan::class, Role::class, Employee::class, Capital::class, ShareHolder::class, InterestFormula::class, CustomerCategory::class, BankAccount::class, ExpenseRequest::class] as $model) {
            $this->assertContains(Auditable::class, class_uses_recursive($model), $model.' must be auditable');
        }

        $this->assertDatabaseHas('audit_logs', ['action' => 'Employee.created', 'auditable_id' => $admin->id]);
    }

    public function test_audit_trail_filters_are_company_scoped_and_require_permission(): void
    {
        $admin = $this->signInAdmin();
        $otherCompany = Company::factory()->create();
        AuditLog::create(['company_id' => $otherCompany->id, 'action' => 'Branch.updated', 'auditable_type' => Branch::class, 'auditable_id' => 1]);
        AuditLog::create(['company_id' => $admin->company_id, 'employee_id' => $admin->id, 'action' => 'Loan.updated', 'auditable_type' => Loan::class, 'auditable_id' => 7, 'before' => ['status' => 'active'], 'after' => ['status' => 'done']]);

        $this->getJson('/api/v1/accounting/audit?model=Loan')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.changes.0', ['field' => 'status', 'before' => 'active', 'after' => 'done']);
        $this->getJson('/api/v1/accounting/audit?employee_id='.$admin->id.'&model=Branch')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/accounting/audit?from=2000-01-01&to=2000-01-31')->assertOk()->assertJsonCount(0, 'data');
        $this->assertContains('Loan', collect($this->getJson('/api/v1/accounting/audit-models')->assertOk()->json('data'))->pluck('value'));

        $finance = $this->employeeWithRole($admin, 'finance');
        $this->actingAs($finance)->getJson('/api/v1/accounting/audit')->assertForbidden();
    }
}
