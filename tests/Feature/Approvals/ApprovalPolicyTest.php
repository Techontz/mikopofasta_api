<?php

namespace Tests\Feature\Approvals;

use App\Enums\Account;
use App\Models\ApprovalPolicy;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\FloatTransfer;
use App\Models\JournalEntry;
use App\Services\AccessControl;
use App\Services\Approvals\SegregationOfDuties;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\UsesSecondApprover;
use Tests\TestCase;

/**
 * C6 company approval policy: the initiator approves their own item only when BOTH the employee explicitly holds
 * approvals.self_approve AND the company policy of the workflow allows self-approval. Only the Super Admin is exempt
 * from approval checks (never from reversals).
 */
class ApprovalPolicyTest extends TestCase
{
    use RefreshDatabase;
    use UsesSecondApprover;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->signInAdmin();
        app(Ledger::class)->openingBalance($this->admin->company_id, Account::Company, 1000000);
    }

    public function test_default_policy_denies_self_approval_even_with_the_explicit_permission(): void
    {
        $requester = $this->secondApprover($this->admin, 'admin');
        $this->grantSelfApproval($requester, withCompanyPolicy: false);
        $id = $this->float($requester);

        $this->assertTrue(app(AccessControl::class)->explicitlyGranted($requester, SegregationOfDuties::PERMISSION));
        $this->assertFalse(app(SegregationOfDuties::class)->canSelfApprove($requester, ApprovalPolicy::FLOATS));
        $this->actingAs($requester)->getJson('/api/v1/capital/floats')->assertJsonPath('data.0.can_approve', false)->assertJsonPath('data.0.approve_blocked_reason', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->actingAs($requester)->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden()->assertJsonPath('message', SegregationOfDuties::INITIATOR_MESSAGE);
        $this->assertSame(0, JournalEntry::where('description', 'like', '%FLOAT%')->count());
        $this->assertSame('pending', FloatTransfer::findOrFail($id)->status);
    }

    public function test_permission_plus_policy_allows_and_the_policy_is_per_workflow(): void
    {
        $requester = $this->secondApprover($this->admin, 'admin');
        $this->grantSelfApproval($requester, withCompanyPolicy: false);
        $id = $this->float($requester);

        $this->allowSelfApprovalPolicy($this->admin->company_id, [ApprovalPolicy::BANK_TRANSFERS]);
        $this->actingAs($requester)->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden();

        $this->allowSelfApprovalPolicy($this->admin->company_id, [ApprovalPolicy::FLOATS]);
        $this->actingAs($requester)->getJson('/api/v1/capital/floats')->assertJsonPath('data.0.can_approve', true);
        $this->actingAs($requester)->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();
        $this->assertSame('approved', FloatTransfer::findOrFail($id)->status);
    }

    public function test_policy_without_the_permission_denies(): void
    {
        $requester = $this->secondApprover($this->admin, 'admin');
        $this->allowSelfApprovalPolicy($this->admin->company_id);
        $id = $this->float($requester);

        $this->assertFalse(app(SegregationOfDuties::class)->canSelfApprove($requester, ApprovalPolicy::FLOATS));
        $this->actingAs($requester)->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden();
    }

    public function test_policy_of_another_company_does_not_apply(): void
    {
        $requester = $this->secondApprover($this->admin, 'admin');
        $this->grantSelfApproval($requester, withCompanyPolicy: false);
        $id = $this->float($requester);

        $other = $this->signInAdmin();
        $this->allowSelfApprovalPolicy($other->company_id);

        $this->actingAs($requester)->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden();
    }

    public function test_super_admin_is_exempt_and_approves_their_own_item_without_the_permission_or_the_policy(): void
    {
        $id = $this->float($this->admin);
        $access = app(AccessControl::class);

        $this->assertNotContains(SegregationOfDuties::PERMISSION, $access->permissionsFor($this->admin));
        $this->assertFalse($access->explicitlyGranted($this->admin, SegregationOfDuties::PERMISSION));
        $this->assertFalse(ApprovalPolicy::where('company_id', $this->admin->company_id)->exists());
        $this->actingAs($this->admin)->getJson('/api/v1/capital/floats')->assertJsonPath('data.0.can_approve', true);
        $this->actingAs($this->admin)->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();
        $this->assertSame('approved', FloatTransfer::findOrFail($id)->status);
    }

    public function test_admin_self_approval_needs_a_visible_explicit_grant_and_the_policy(): void
    {
        $access = app(AccessControl::class);
        $initiator = $this->secondApprover($this->admin, 'admin');
        $id = $this->float($initiator);

        // An employee override is honoured only because permissionsFor() shows it.
        $this->grantSelfApproval($initiator, withCompanyPolicy: false);
        $this->assertContains(SegregationOfDuties::PERMISSION, $access->permissionsFor($initiator));
        $this->assertTrue($access->explicitlyGranted($initiator, SegregationOfDuties::PERMISSION));
        $this->actingAs($initiator)->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden();

        // A revoking override on top of a role grant is not shown and not honoured.
        $initiator->role->permissions()->create(['permission' => SegregationOfDuties::PERMISSION]);
        $initiator->permissionOverrides()->where('permission', SegregationOfDuties::PERMISSION)->update(['granted' => false]);
        $fresh = $initiator->fresh();
        $this->assertNotContains(SegregationOfDuties::PERMISSION, $access->permissionsFor($fresh));
        $this->assertFalse($access->explicitlyGranted($fresh, SegregationOfDuties::PERMISSION));

        // The role grant (visible) plus the policy allows.
        $fresh->permissionOverrides()->where('permission', SegregationOfDuties::PERMISSION)->delete();
        $fresh = $initiator->fresh();
        $this->assertContains(SegregationOfDuties::PERMISSION, $access->permissionsFor($fresh));
        $this->actingAs($fresh)->postJson("/api/v1/capital/floats/{$id}/approve")->assertForbidden();
        $this->allowSelfApprovalPolicy($this->admin->company_id, [ApprovalPolicy::FLOATS]);
        $this->actingAs($fresh)->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();
    }

    public function test_reversal_by_the_poster_follows_the_reversal_policy(): void
    {
        // Only Super Admin and Admin approve floats; this Admin also holds accounting.reverse so the reversal policy is what blocks.
        $poster = $this->secondApprover($this->admin, 'admin');
        $poster->permissionOverrides()->create(['permission' => 'accounting.reverse', 'granted' => true]);
        $id = $this->float($this->admin);
        $this->actingAs($poster)->postJson("/api/v1/capital/floats/{$id}/approve")->assertOk();

        $this->grantSelfApproval($poster, withCompanyPolicy: false);
        $this->actingAs($poster)->postJson("/api/v1/capital/floats/{$id}/reverse", ['reason' => 'Mistake'])->assertForbidden()->assertJsonPath('message', SegregationOfDuties::REVERSER_MESSAGE);

        $this->allowSelfApprovalPolicy($this->admin->company_id, [ApprovalPolicy::REVERSALS]);
        $this->actingAs($poster)->postJson("/api/v1/capital/floats/{$id}/reverse", ['reason' => 'Mistake'])->assertOk();
    }

    public function test_settings_api_lists_defaults_updates_self_approval_and_audits_every_change(): void
    {
        $this->getJson('/api/v1/settings/approval-policies')->assertOk()
            ->assertJsonCount(count(ApprovalPolicy::WORKFLOWS), 'data')
            ->assertJsonPath('data.0.workflow', ApprovalPolicy::EXPENSES)
            ->assertJsonPath('data.0.requires_approval', true)
            ->assertJsonPath('data.0.allow_self_approval', false);

        $this->putJson('/api/v1/settings/approval-policies', ['policies' => [['workflow' => 'unknown.flow', 'allow_self_approval' => true]]])->assertUnprocessable()->assertJsonValidationErrors('policies.0.workflow');
        $this->putJson('/api/v1/settings/approval-policies', ['policies' => []])->assertUnprocessable();

        $this->putJson('/api/v1/settings/approval-policies', ['policies' => [
            ['workflow' => ApprovalPolicy::FLOATS, 'allow_self_approval' => true],
            ['workflow' => ApprovalPolicy::EXPENSES, 'allow_self_approval' => false],
        ]])->assertOk()->assertJsonPath('data.1.workflow', ApprovalPolicy::FLOATS)->assertJsonPath('data.1.allow_self_approval', true)->assertJsonPath('data.1.updated_by', $this->admin->full_name);

        $this->assertDatabaseHas('approval_policies', ['company_id' => $this->admin->company_id, 'workflow' => ApprovalPolicy::FLOATS, 'allow_self_approval' => true, 'requires_approval' => true, 'updated_by' => $this->admin->id]);
        $this->assertDatabaseMissing('approval_policies', ['workflow' => ApprovalPolicy::EXPENSES]);
        $audit = AuditLog::where('action', 'ApprovalPolicy.updated')->sole();
        $this->assertSame([['workflow' => ApprovalPolicy::FLOATS, 'allow_self_approval' => false], ['workflow' => ApprovalPolicy::FLOATS, 'allow_self_approval' => true]], [$audit->before, $audit->after]);

        $this->putJson('/api/v1/settings/approval-policies', ['policies' => [['workflow' => ApprovalPolicy::FLOATS, 'allow_self_approval' => false]]])->assertOk();
        $this->assertSame(2, AuditLog::where('action', 'ApprovalPolicy.updated')->count());

        foreach (['finance', 'teller'] as $role) {
            $this->actingAs($this->secondApprover($this->admin, $role))->getJson('/api/v1/settings/approval-policies')->assertForbidden();
            $this->putJson('/api/v1/settings/approval-policies', ['policies' => [['workflow' => ApprovalPolicy::FLOATS, 'allow_self_approval' => true]]])->assertForbidden();
        }

        $this->actingAs($this->admin)->putJson('/api/v1/settings/approval-policies', ['policies' => [['workflow' => ApprovalPolicy::FLOATS, 'allow_self_approval' => true]]])->assertOk();
        $this->signInAdmin();
        $this->getJson('/api/v1/settings/approval-policies')->assertOk()->assertJsonPath('data.1.allow_self_approval', false);
    }

    public function test_permissions_config_never_grants_self_approval_or_admin_reversal(): void
    {
        $roles = config('permissions.roles');
        $this->assertContains('approvals.view', $roles['admin']['permissions']);
        $this->assertContains('approvals.view', $roles['finance']['permissions']);
        $this->assertContains('approvals.view', app(AccessControl::class)->permissionsFor($this->admin), 'Super Admin holds it implicitly');
        $this->assertNotContains('accounting.reverse', $roles['admin']['permissions']);
        foreach ($roles as $key => $role) {
            $this->assertNotContains('approvals.self_approve', $role['permissions'], $key);
        }
        $this->assertContains('approvals.self_approve', config('permissions.explicit_only'));
        $this->assertNotContains('approvals.view', config('permissions.explicit_only'));

        $this->assertFalse($this->admin->company->roles()->where('key', 'admin')->firstOrFail()->permissions()->where('permission', 'accounting.reverse')->exists());
        foreach (File::files(database_path('migrations')) as $migration) {
            $source = File::get($migration->getPathname());
            $this->assertFalse(str_contains($source, "'approvals.self_approve'") && str_contains($source, 'role_permissions'), $migration->getFilename().' must not grant approvals.self_approve');
            if (str_contains($source, "'accounting.reverse'")) {
                $this->assertDoesNotMatchRegularExpression("/'admin'/", $source, $migration->getFilename().' must not grant accounting.reverse to Admin');
            }
        }
    }

    private function float(Employee $requester): int
    {
        return $this->actingAs($requester)->postJson('/api/v1/capital/floats', ['amount' => 1000, 'from_account' => Account::Company->value])->assertCreated()->json('data.id');
    }
}
