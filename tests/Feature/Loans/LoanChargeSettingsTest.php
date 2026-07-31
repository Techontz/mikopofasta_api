<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Enums\ChargeValueType;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\LoanFee;
use App\Models\LoanProduct;
use App\Models\PenaltySetting;
use App\Models\ReserveSetting;

/**
 * Loan Charges & Reserve — Settings → Loan Fee / Penalty / Reserve Setting.
 * See docs/modules/loan-charges.md.
 */
beforeEach(function (): void {
    seedLoanFoundation();
});

describe('loan fee', function (): void {
    it('lists every loan category, including those with no fee configured', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $products = LoanProduct::query()->count();

        $response = $this->getJson('/api/v1/loan-fees')->assertOk();

        expect($response->json('data'))->toHaveCount($products);
        // The legacy screen shows every category whether priced or not, so an
        // unpriced product must appear with a null fee rather than be omitted.
        expect($response->json('data.0.fee'))->toBeNull();
        expect($response->json('data.0.productName'))->not->toBeNull();
    });

    it('configures a fee and returns the product level alongside it', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $product = LoanProduct::query()->firstOrFail();

        $this->putJson("/api/v1/loan-fees/{$product->id}", [
            'feeType' => ChargeValueType::MoneyValue->value,
            'feeAmount' => '5000',
            'insuranceAmount' => '5000',
        ])
            ->assertOk()
            ->assertJsonPath('data.feeType', 'money_value')
            ->assertJsonPath('data.feeTypeLabel', 'MONEY VALUE')
            ->assertJsonPath('data.feeAmount', '5000.00')
            ->assertJsonPath('data.productName', $product->name);

        expect(LoanFee::query()->where('loan_product_id', $product->id)->count())->toBe(1);
    });

    it('updates in place rather than creating a second row for the same product', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $product = LoanProduct::query()->firstOrFail();

        foreach (['5000', '7500'] as $amount) {
            $this->putJson("/api/v1/loan-fees/{$product->id}", [
                'feeType' => ChargeValueType::MoneyValue->value,
                'feeAmount' => $amount,
                'insuranceAmount' => '0',
            ])->assertOk();
        }

        expect(LoanFee::withTrashed()->where('loan_product_id', $product->id)->count())->toBe(1);
        expect(LoanFee::query()->where('loan_product_id', $product->id)->value('fee_amount'))->toBe('7500.00');
    });

    it('revives a cleared fee instead of colliding with the unique index', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $product = LoanProduct::query()->firstOrFail();

        $payload = ['feeType' => ChargeValueType::MoneyValue->value, 'feeAmount' => '5000', 'insuranceAmount' => '0'];

        $this->putJson("/api/v1/loan-fees/{$product->id}", $payload)->assertOk();
        $this->deleteJson("/api/v1/loan-fees/{$product->id}")->assertOk();
        $this->putJson("/api/v1/loan-fees/{$product->id}", $payload)->assertOk();

        expect(LoanFee::withTrashed()->where('loan_product_id', $product->id)->count())->toBe(1);
        expect(LoanFee::query()->where('loan_product_id', $product->id)->exists())->toBeTrue();
    });

    it('rejects a percentage fee above 100 but allows the same figure as money', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $product = LoanProduct::query()->firstOrFail();

        $this->putJson("/api/v1/loan-fees/{$product->id}", [
            'feeType' => ChargeValueType::PercentageValue->value,
            'feeAmount' => '150',
            'insuranceAmount' => '0',
        ])->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_FAILED');

        // The very same number is a legitimate flat fee — the ceiling depends
        // on the unit, which is the whole point of ChargeValueType.
        $this->putJson("/api/v1/loan-fees/{$product->id}", [
            'feeType' => ChargeValueType::MoneyValue->value,
            'feeAmount' => '150',
            'insuranceAmount' => '0',
        ])->assertOk();
    });

    it('404s when clearing a fee that was never configured', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $product = LoanProduct::query()->firstOrFail();

        $this->deleteJson("/api/v1/loan-fees/{$product->id}")
            ->assertNotFound()
            ->assertJsonPath('error_code', 'RESOURCE_NOT_FOUND');
    });
});

describe('penalty setting', function (): void {
    it('records a default and lists it newest first', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->postJson('/api/v1/penalty-settings', [
            'calculationType' => ChargeValueType::PercentageValue->value,
            'amount' => '20',
        ])
            ->assertCreated()
            ->assertJsonPath('data.calculationTypeLabel', 'PERCENTAGE VALUE')
            ->assertJsonPath('data.amount', '20.000');

        $this->postJson('/api/v1/penalty-settings', [
            'calculationType' => ChargeValueType::MoneyValue->value,
            'amount' => '10000',
        ])->assertCreated();

        $response = $this->getJson('/api/v1/penalty-settings')->assertOk();
        expect($response->json('data.0.calculationType'))->toBe('money_value');
    });

    /**
     * The load-bearing test for this module. A global penalty default must not
     * re-price anything already on the book — see the boundary note in the
     * module doc.
     */
    it('does not alter loan product penalty configuration', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $before = LoanProduct::query()
            ->get(['id', 'penalty_type', 'penalty_rate', 'penalty_grace_days', 'penalty_cap_amount'])
            ->map(fn (LoanProduct $p): array => $p->only(['penalty_type', 'penalty_rate', 'penalty_grace_days', 'penalty_cap_amount']))
            ->toArray();

        $this->postJson('/api/v1/penalty-settings', [
            'calculationType' => ChargeValueType::PercentageValue->value,
            'amount' => '99',
        ])->assertCreated();

        $after = LoanProduct::query()
            ->get(['id', 'penalty_type', 'penalty_rate', 'penalty_grace_days', 'penalty_cap_amount'])
            ->map(fn (LoanProduct $p): array => $p->only(['penalty_type', 'penalty_rate', 'penalty_grace_days', 'penalty_cap_amount']))
            ->toArray();

        expect($after)->toBe($before);
    });

    it('rejects a percentage above 100', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->postJson('/api/v1/penalty-settings', [
            'calculationType' => ChargeValueType::PercentageValue->value,
            'amount' => '101',
        ])->assertStatus(422);
    });

    it('deletes a default', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $setting = PenaltySetting::query()->create([
            'calculation_type' => ChargeValueType::PercentageValue,
            'amount' => '20',
        ]);

        $this->deleteJson("/api/v1/penalty-settings/{$setting->id}")->assertOk();

        expect(PenaltySetting::query()->find($setting->id))->toBeNull();
    });
});

describe('reserve setting', function (): void {
    it('returns a single row on first read rather than nothing', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->getJson('/api/v1/reserve-setting')
            ->assertOk()
            ->assertJsonPath('data.percentage', '0.000');

        expect(ReserveSetting::query()->count())->toBe(1);
    });

    it('updates the same row every time instead of inserting more', function (): void {
        officerAt('Head Office', RoleName::Admin);

        foreach (['20', '25'] as $percentage) {
            $this->putJson('/api/v1/reserve-setting', ['percentage' => $percentage])->assertOk();
        }

        expect(ReserveSetting::query()->count())->toBe(1);
        expect(ReserveSetting::query()->value('percentage'))->toBe('25.000');
    });

    it('rejects a percentage outside 0–100', function (): void {
        officerAt('Head Office', RoleName::Admin);

        $this->putJson('/api/v1/reserve-setting', ['percentage' => '101'])->assertStatus(422);
        $this->putJson('/api/v1/reserve-setting', ['percentage' => '-1'])->assertStatus(422);
    });
});

describe('rbac', function (): void {
    it('lets a role without admin.org_settings read but never write', function (): void {
        // Loan Officer holds loans.* and customers.*, never admin.org_settings.
        officerAt('Kakonko', RoleName::LoanOfficer);
        $product = LoanProduct::query()->firstOrFail();

        $this->getJson('/api/v1/loan-fees')->assertOk();
        $this->getJson('/api/v1/penalty-settings')->assertOk();
        $this->getJson('/api/v1/reserve-setting')->assertOk();

        $this->putJson("/api/v1/loan-fees/{$product->id}", [
            'feeType' => ChargeValueType::MoneyValue->value,
            'feeAmount' => '5000',
            'insuranceAmount' => '0',
        ])->assertForbidden();
        $this->postJson('/api/v1/penalty-settings', [
            'calculationType' => ChargeValueType::PercentageValue->value,
            'amount' => '20',
        ])->assertForbidden();
        $this->putJson('/api/v1/reserve-setting', ['percentage' => '20'])->assertForbidden();
    });

    it('refuses an unauthenticated caller outright', function (): void {
        $this->getJson('/api/v1/loan-fees')->assertUnauthorized();
        $this->getJson('/api/v1/reserve-setting')->assertUnauthorized();
    });
});

describe('audit trail', function (): void {
    it('records who changed each charge', function (): void {
        $actor = officerAt('Head Office', RoleName::Admin);
        $product = LoanProduct::query()->firstOrFail();

        $this->putJson("/api/v1/loan-fees/{$product->id}", [
            'feeType' => ChargeValueType::MoneyValue->value,
            'feeAmount' => '5000',
            'insuranceAmount' => '1000',
        ])->assertOk();
        $this->putJson('/api/v1/reserve-setting', ['percentage' => '20'])->assertOk();
        $this->postJson('/api/v1/penalty-settings', [
            'calculationType' => ChargeValueType::PercentageValue->value,
            'amount' => '20',
        ])->assertCreated();

        foreach ([AuditAction::LoanFeeConfigured, AuditAction::ReserveSettingUpdated, AuditAction::PenaltySettingCreated] as $action) {
            expect(AuditLog::query()->where('action', $action->value)->where('user_id', $actor->id)->exists())
                ->toBeTrue("expected an audit entry for {$action->value}");
        }
    });

    it('captures the previous fee so a change is readable both ways', function (): void {
        officerAt('Head Office', RoleName::Admin);
        $product = LoanProduct::query()->firstOrFail();

        $this->putJson("/api/v1/loan-fees/{$product->id}", [
            'feeType' => ChargeValueType::MoneyValue->value, 'feeAmount' => '5000', 'insuranceAmount' => '0',
        ])->assertOk();
        $this->putJson("/api/v1/loan-fees/{$product->id}", [
            'feeType' => ChargeValueType::MoneyValue->value, 'feeAmount' => '7500', 'insuranceAmount' => '0',
        ])->assertOk();

        $latest = AuditLog::query()->where('action', AuditAction::LoanFeeConfigured->value)->latest('id')->firstOrFail();

        expect($latest->before_json['fee_amount'])->toBe('5000.00');
        expect($latest->after_json['fee_amount'])->toBe('7500.00');
    });
});
