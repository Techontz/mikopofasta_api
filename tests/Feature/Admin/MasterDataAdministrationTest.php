<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Models\Customer;
use App\Models\LoanApprovalStage;
use App\Models\MasterData\Sector;
use App\Models\MasterData\SectorCategory;

/**
 * Administration → Master Data, and the two chains that hang off it.
 *
 * THE PRINCIPLE THESE PROTECT. This application ships no institutional
 * reference data. A fresh installation starts with every list empty, and an
 * administrator states which banks, documents, sectors and employers their
 * institution uses before the first customer is registered. That is only true
 * if the write paths actually exist — which, for cadres and approval stages,
 * they did not until now.
 *
 * Nothing here asserts that a particular bank or sector EXISTS. What is in the
 * lists is the institution's business; what these prove is that an
 * administrator can put it there, and that nobody else can.
 */
beforeEach(function (): void {
    seedCustomerFoundation();
    /* The approval chain is its own reference data and `seedCustomerFoundation`
       does not carry it — a test that needs stages says so, which is the whole
       point of the seeders no longer being implicit. */
    test()->seed(Database\Seeders\LoanApprovalStageSeeder::class);
});

/* -------------------------------------------------------------------------
 | The lookup lists
 |------------------------------------------------------------------------- */

describe('lookup lists', function (): void {
    it('lets an administrator create an entry in every managed list', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $lists = [
            'customer-types', 'account-types', 'loan-types', 'work-types', 'employment-types',
            'occupations', 'banks', 'mobile-money-providers', 'marital-statuses',
            'document-types', 'id-types', 'contract-types', 'sectors', 'employers',
        ];

        foreach ($lists as $list) {
            $this->postJson("/api/v1/master-data/{$list}", [
                'code' => 'TEST_'.strtoupper(str_replace('-', '_', $list)),
                'name' => 'Test entry',
                'sortOrder' => 999,
            ])->assertCreated();
        }

        /* And each one now offers it — the registration form reads exactly
           this endpoint. */
        foreach ($lists as $list) {
            expect(collect($this->getJson("/api/v1/master-data/{$list}?active=1")->json('data'))->pluck('code'))
                ->toContain('TEST_'.strtoupper(str_replace('-', '_', $list)));
        }
    });

    it('lets an administrator deactivate an entry without deleting it', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $id = $this->postJson('/api/v1/master-data/banks', ['code' => 'TESTBANK', 'name' => 'Test Bank'])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/v1/master-data/banks/{$id}", [
            'code' => 'TESTBANK', 'name' => 'Test Bank', 'isActive' => false,
        ])->assertOk();

        /* Gone from what a form may offer, still readable on the records that
           already point at it. */
        expect(collect($this->getJson('/api/v1/master-data/banks?active=1')->json('data'))->pluck('code'))
            ->not->toContain('TESTBANK')
            ->and(collect($this->getJson('/api/v1/master-data/banks')->json('data'))->pluck('code'))
            ->toContain('TESTBANK');
    });

    it('refuses a duplicate code', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $this->postJson('/api/v1/master-data/banks', ['code' => 'DUP', 'name' => 'First'])->assertCreated();
        $this->postJson('/api/v1/master-data/banks', ['code' => 'DUP', 'name' => 'Second'])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
    });

    it('refuses a write from a role without admin.org_settings', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/master-data/banks', ['code' => 'NOPE', 'name' => 'Nope'])
            ->assertForbidden();
    });

    /* An officer must be able to READ the lists — the registration form they
       use is built from them. */
    it('lets any authenticated user read a list', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/master-data/banks')->assertOk();
    });
});

/* -------------------------------------------------------------------------
 | Sector → cadre
 |------------------------------------------------------------------------- */

describe('sector cadres', function (): void {
    it('lets an administrator build a sector and its cadres', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $sectorId = $this->postJson('/api/v1/master-data/sectors', [
            'code' => 'TEST_BODY', 'name' => 'Test Employing Body',
        ])->assertCreated()->json('data.id');

        foreach (['CADRE_A' => 'Cadre A', 'CADRE_B' => 'Cadre B'] as $code => $name) {
            $this->postJson('/api/v1/master-data/sector-categories', [
                'sectorId' => $sectorId, 'code' => $code, 'name' => $name,
            ])->assertCreated();
        }

        expect(collect($this->getJson("/api/v1/master-data/sector-categories?sector_id={$sectorId}")->json('data'))->pluck('code')->all())
            ->toBe(['CADRE_A', 'CADRE_B']);
    });

    /* Two employing bodies may each have an "Administration" cadre and they
       are not the same job. */
    it('allows the same cadre code under two different sectors', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $first = $this->postJson('/api/v1/master-data/sectors', ['code' => 'BODY_ONE', 'name' => 'One'])
            ->assertCreated()->json('data.id');
        $second = $this->postJson('/api/v1/master-data/sectors', ['code' => 'BODY_TWO', 'name' => 'Two'])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/master-data/sector-categories', ['sectorId' => $first, 'code' => 'ADMIN', 'name' => 'Administration'])->assertCreated();
        $this->postJson('/api/v1/master-data/sector-categories', ['sectorId' => $second, 'code' => 'ADMIN', 'name' => 'Administration'])->assertCreated();
    });

    it('refuses a duplicate cadre code within one sector', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $sectorId = $this->postJson('/api/v1/master-data/sectors', ['code' => 'BODY', 'name' => 'Body'])
            ->assertCreated()->json('data.id');

        $this->postJson('/api/v1/master-data/sector-categories', ['sectorId' => $sectorId, 'code' => 'SAME', 'name' => 'First'])->assertCreated();
        $this->postJson('/api/v1/master-data/sector-categories', ['sectorId' => $sectorId, 'code' => 'SAME', 'name' => 'Second'])
            ->assertStatus(422)->assertJsonValidationErrors(['code']);
    });

    /* Moving a cadre between employing bodies would silently rewrite what
       every customer under it does. */
    it('will not move a cadre to another sector', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $cadre = SectorCategory::query()->firstOrFail();
        $other = Sector::query()->create(['code' => 'ELSEWHERE', 'name' => 'Elsewhere', 'is_active' => true]);

        $this->putJson("/api/v1/master-data/sector-categories/{$cadre->id}", [
            'sectorId' => $other->getKey(), 'code' => $cadre->code, 'name' => $cadre->name,
        ])->assertStatus(422)->assertJsonValidationErrors(['sectorId']);
    });

    it('refuses to delete a cadre customers are filed under', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $cadre = SectorCategory::query()->firstOrFail();

        /* A customer has to exist to be filed under it — this suite seeds
           reference data, not a customer book. */
        $customer = registeredCustomer();
        $customer->forceFill([
            'sector_id' => $cadre->sector_id, 'sector_category_id' => $cadre->getKey(),
        ])->save();

        $this->deleteJson("/api/v1/master-data/sector-categories/{$cadre->id}")->assertStatus(409);

        expect(SectorCategory::query()->whereKey($cadre->getKey())->exists())->toBeTrue();
    });

    it('refuses a cadre write from a role without admin.org_settings', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->postJson('/api/v1/master-data/sector-categories', [
            'sectorId' => Sector::query()->value('id'), 'code' => 'NOPE', 'name' => 'Nope',
        ])->assertForbidden();
    });
});

/* -------------------------------------------------------------------------
 | The approval chain
 |------------------------------------------------------------------------- */

describe('the loan approval chain', function (): void {
    it('reports the chain and the statuses a stage may hold', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $body = $this->getJson('/api/v1/loan-approval-stages')->assertOk()->json('data');

        expect($body['stages'])->not->toBeEmpty()
            ->and($body['availableStatuses'])->toContain('pending_manager_approval');
    });

    it('lets an administrator add a stage and reorder the chain', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $this->postJson('/api/v1/loan-approval-stages', [
            'code' => 'REGIONAL', 'name' => 'Regional Review', 'sequence' => 25,
            'loanStatus' => 'pending_zone_approval', 'requiredPermission' => 'loans.zone_approve',
        ])->assertCreated();

        expect(LoanApprovalStage::query()->orderBy('sequence')->pluck('code')->all())
            ->toContain('REGIONAL');
    });

    it('refuses a stage at a status the loan column cannot hold', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $this->postJson('/api/v1/loan-approval-stages', [
            'code' => 'BAD', 'name' => 'Bad', 'sequence' => 99,
            'loanStatus' => 'invented_status', 'requiredPermission' => 'loans.approve',
        ])->assertStatus(422)->assertJsonValidationErrors(['loanStatus']);
    });

    /* A stage naming a permission nobody holds is one no loan can ever leave. */
    it('refuses a stage whose permission the application does not define', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $this->postJson('/api/v1/loan-approval-stages', [
            'code' => 'BAD', 'name' => 'Bad', 'sequence' => 99,
            'loanStatus' => 'pending_credit_review', 'requiredPermission' => 'loans.imaginary',
        ])->assertStatus(422)->assertJsonValidationErrors(['requiredPermission']);
    });

    it('refuses two stages claiming the same position', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $existing = LoanApprovalStage::query()->firstOrFail();

        $this->postJson('/api/v1/loan-approval-stages', [
            'code' => 'CLASH', 'name' => 'Clash', 'sequence' => $existing->sequence,
            'loanStatus' => 'pending_credit_review', 'requiredPermission' => 'loans.credit_review',
        ])->assertStatus(422)->assertJsonValidationErrors(['sequence']);
    });

    it('lets a stage be deactivated', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $stage = LoanApprovalStage::query()->orderBy('sequence')->firstOrFail();

        $this->putJson("/api/v1/loan-approval-stages/{$stage->id}", [
            'code' => $stage->code, 'name' => $stage->name, 'sequence' => $stage->sequence,
            'loanStatus' => $stage->loan_status->value,
            'requiredPermission' => $stage->required_permission,
            'isActive' => false,
        ])->assertOk();

        expect($stage->refresh()->is_active)->toBeFalse();
    });

    it('refuses the chain to a role without admin.org_settings', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/loan-approval-stages')->assertForbidden();
        $this->postJson('/api/v1/loan-approval-stages', [
            'code' => 'NOPE', 'name' => 'Nope', 'sequence' => 99,
            'loanStatus' => 'pending_credit_review', 'requiredPermission' => 'loans.credit_review',
        ])->assertForbidden();
    });

    it('records who changed the chain', function (): void {
        officerAt('Kakonko', RoleName::Admin);

        $this->postJson('/api/v1/loan-approval-stages', [
            'code' => 'AUDITED', 'name' => 'Audited', 'sequence' => 77,
            'loanStatus' => 'pending_credit_review', 'requiredPermission' => 'loans.credit_review',
        ])->assertCreated();

        expect(DB::table('audit_logs')->where('action', 'APPROVAL_STAGE_CREATED')->exists())->toBeTrue();
    });
});
