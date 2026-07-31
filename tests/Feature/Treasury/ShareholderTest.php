<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\CapitalContribution;
use App\Models\Shareholder;

/**
 * Capital → Share Holders. See docs/modules/capital.md.
 */

beforeEach(function (): void {
    seedCustomerFoundation();
});

function shareholderPayload(array $overrides = []): array
{
    return array_merge([
        'fullName' => 'Mseti Ally',
        'phone' => '0777000111',
        'email' => 'mseti@example.com',
        'gender' => 'male',
        'dateOfBirth' => '1992-12-12',
    ], $overrides);
}

describe('registering', function (): void {
    it('registers a shareholder and lists them', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/shareholders', shareholderPayload())
            ->assertCreated()
            ->assertJsonPath('data.fullName', 'Mseti Ally')
            ->assertJsonPath('data.gender', 'male')
            ->assertJsonPath('data.dateOfBirth', '1992-12-12');

        $response = $this->getJson('/api/v1/shareholders')->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.contributionCount'))->toBe(0);
    });

    it('refuses a duplicate phone or email', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $this->postJson('/api/v1/shareholders', shareholderPayload())->assertCreated();

        $this->postJson('/api/v1/shareholders', shareholderPayload(['email' => 'other@example.com']))
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'A shareholder with this phone number already exists.');

        $this->postJson('/api/v1/shareholders', shareholderPayload(['phone' => '0777000222']))
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'A shareholder with this email already exists.');
    });

    it('refuses a birth date that is not in the past', function (): void {
        officerAt('Head Office', RoleName::Finance);

        $this->postJson('/api/v1/shareholders', shareholderPayload(['dateOfBirth' => now()->addYear()->toDateString()]))
            ->assertStatus(422)
            ->assertJsonPath('errors.dateOfBirth.0', 'Date of birth must be in the past.');
    });
});

describe('editing', function (): void {
    it('updates a shareholder without tripping its own uniqueness rules', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/shareholders', shareholderPayload())->json('data.id');

        // Same phone and email, different name — the record must be excluded
        // from its own unique check or every edit would fail.
        $this->putJson("/api/v1/shareholders/{$id}", shareholderPayload(['fullName' => 'Mseti Ally Juma']))
            ->assertOk()
            ->assertJsonPath('data.fullName', 'Mseti Ally Juma');
    });
});

describe('deleting', function (): void {
    it('deletes a shareholder who holds no capital', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/shareholders', shareholderPayload())->json('data.id');

        $this->deleteJson("/api/v1/shareholders/{$id}")->assertOk();

        expect(Shareholder::query()->find($id))->toBeNull();
    });

    it('refuses to delete a shareholder with capital recorded against them', function (): void {
        officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/shareholders', shareholderPayload())->json('data.id');

        CapitalContribution::query()->create([
            'shareholder_id' => $id,
            'amount' => '1000000',
            'pay_method' => 'cash',
        ]);

        $this->deleteJson("/api/v1/shareholders/{$id}")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'RESOURCE_IN_USE');

        expect(Shareholder::query()->find($id))->not->toBeNull();
    });
});

describe('rbac', function (): void {
    it('requires treasury.view to read and treasury.manage to write', function (): void {
        // Loan Officer holds neither treasury permission.
        officerAt('Kakonko', RoleName::LoanOfficer);

        $this->getJson('/api/v1/shareholders')->assertForbidden();
        $this->postJson('/api/v1/shareholders', shareholderPayload())->assertForbidden();
    });

    it('lets a read-only treasury role list but not register', function (): void {
        /*
         * Admin holds treasury.view but deliberately NOT treasury.manage:
         * administering the system and moving the company's money are two
         * different jobs (§14). Auditor is in the same position.
         */
        officerAt('Head Office', RoleName::Admin);

        $this->getJson('/api/v1/shareholders')->assertOk();
        $this->postJson('/api/v1/shareholders', shareholderPayload())->assertForbidden();
    });

    it('refuses an unauthenticated caller', function (): void {
        $this->getJson('/api/v1/shareholders')->assertUnauthorized();
    });
});

describe('audit trail', function (): void {
    it('records registration, edit and deletion', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $id = $this->postJson('/api/v1/shareholders', shareholderPayload())->json('data.id');
        $this->putJson("/api/v1/shareholders/{$id}", shareholderPayload(['fullName' => 'Changed Name']))->assertOk();
        $this->deleteJson("/api/v1/shareholders/{$id}")->assertOk();

        foreach ([AuditAction::ShareholderRegistered, AuditAction::ShareholderUpdated, AuditAction::ShareholderDeleted] as $action) {
            expect(AuditLog::query()->where('action', $action->value)->where('user_id', $actor->id)->exists())
                ->toBeTrue("expected an audit entry for {$action->value}");
        }

        $edit = AuditLog::query()->where('action', AuditAction::ShareholderUpdated->value)->latest('id')->firstOrFail();
        expect($edit->before_json['full_name'])->toBe('Mseti Ally')
            ->and($edit->after_json['full_name'])->toBe('Changed Name');
    });
});
