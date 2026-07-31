<?php

declare(strict_types=1);

use App\Console\Commands\ApplyPenaltiesCommand;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Ledger\DTOs\JournalLine;
use App\Domain\Ledger\Enums\JournalSourceType;
use App\Domain\Ledger\Enums\SystemAccountCode;
use App\Domain\Ledger\Exceptions\UnbalancedEntryException;
use App\Domain\Ledger\Services\AccountResolver;
use App\Domain\Ledger\Services\LedgerService;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Support\Money;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * The operational trace an on-call engineer reads, and the audit trail an
 * auditor reads. Two different records for two different readers: the audit
 * log answers "who decided this", the operations log answers "what happened
 * and why did it fail".
 *
 * Every assertion here also checks the negative — that no secret reached the
 * log — because a log that leaks a signature is worse than no log.
 */
beforeEach(function (): void {
    seedLedgerFoundation();
});

describe('the operations channel', function (): void {
    it('is configured with its own file and a longer retention than the app log', function (): void {
        $channel = config('logging.channels.operations');

        expect($channel['driver'])->toBe('daily')
            ->and($channel['path'])->toContain('operations.log')
            // Money events must outlive the 14-day application log.
            ->and($channel['days'])->toBeGreaterThanOrEqual(90);
    });

    it('records a webhook rejection without the signature or the body', function (): void {
        Config::set('webhooks.providers.payments.secret', 'a-secret');

        $captured = [];
        Log::listen(function ($event) use (&$captured): void {
            $captured[] = $event;
        });

        forgetAuthGuards();
        $this->postJson('/webhooks/payments', [
            'reference' => 'LN-2026-000001',
            'amount' => '20000.00',
            'channel' => 'mobile_money',
            'transactionId' => 'TXN-LOG-1',
        ], ['X-Bank-Signature' => 'deadbeef'])->assertStatus(401);

        $rejection = collect($captured)->first(fn ($e): bool => $e->message === 'Webhook rejected');

        expect($rejection)->not->toBeNull()
            ->and($rejection->context['provider'])->toBe('payments')
            ->and($rejection->context['reason'])->toBe('signature_mismatch');

        // The reason is for the operator; nothing that could help an attacker
        // or expose a secret is written.
        $serialised = json_encode($rejection->context);

        expect($serialised)->not->toContain('deadbeef')
            ->and($serialised)->not->toContain('a-secret')
            ->and($serialised)->not->toContain('TXN-LOG-1');
    });

    it('records a scheduler run start and completion', function (): void {
        activeLoan();

        $captured = [];
        Log::listen(function ($event) use (&$captured): void {
            $captured[] = $event->message;
        });

        $this->artisan(ApplyPenaltiesCommand::class)->assertSuccessful();

        expect($captured)->toContain('Penalty run starting')
            ->and($captured)->toContain('Penalty run complete');
    });

    it('records a ledger posting that was rejected for not balancing', function (): void {
        $actor = officerAt('Head Office', RoleName::Finance);
        $accounts = app(AccountResolver::class);

        $captured = [];
        Log::listen(function ($event) use (&$captured): void {
            $captured[] = $event;
        });

        try {
            app(LedgerService::class)->post(
                description: 'Deliberately unbalanced',
                sourceType: JournalSourceType::CapitalInjection,
                sourceId: null,
                lines: [
                    JournalLine::debit((int) $accounts->defaultBankAccount()->getKey(), Money::of('100.00')),
                    JournalLine::credit($accounts->systemId(SystemAccountCode::Capital), Money::of('99.99')),
                ],
                postedBy: $actor,
            );
        } catch (UnbalancedEntryException) {
            // Expected — the point is what was logged on the way out.
        }

        $rejection = collect($captured)
            ->first(fn ($e): bool => str_contains($e->message, 'Ledger posting rejected'));

        expect($rejection)->not->toBeNull()
            // `critical`, because a builder producing an unbalanced entry is
            // the one failure that would corrupt the books if it succeeded.
            ->and($rejection->level)->toBe('critical')
            ->and($rejection->context['difference'])->toBe('0.01');
    });

    it('records payments received, matched and processed', function (): void {
        $loan = activeLoan();

        $captured = [];
        Log::listen(function ($event) use (&$captured): void {
            $captured[] = $event->message;
        });

        officerAt($loan->branch->name, RoleName::Teller);
        $this->postJson('/api/v1/payments/cash', ['loanId' => $loan->getKey(), 'amount' => '5000.00'])
            ->assertCreated();

        expect($captured)->toContain('Repayment processed');
    });

    it('records payroll generation, finalization and payment', function (): void {
        seedLedgerActivity();
        test()->seed(Database\Seeders\StaffSeeder::class);
        forgetAuthGuards();

        $captured = [];
        Log::listen(function ($event) use (&$captured): void {
            $captured[] = $event->message;
        });

        $run = finalizedPayrollRun();

        actingAsFinance();
        $this->postJson("/api/v1/payroll/{$run->id}/pay")->assertOk();

        expect($captured)->toContain('Payroll draft generated')
            ->and($captured)->toContain('Payroll finalized and posted')
            ->and($captured)->toContain('Payroll paid');
    });

    it('records a disbursement settlement', function (): void {
        $captured = [];
        Log::listen(function ($event) use (&$captured): void {
            $captured[] = $event->message;
        });

        activeLoan();

        expect($captured)->toContain('Disbursement settled and loan activated');
    });
});

describe('audit coverage for customer relations', function (): void {
    it('records a guarantor being added', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", [
            'name' => 'Salma Kimaro',
            'phone' => '0755888111',
            'relationship' => 'friend',
            'address' => 'Kakonko',
            'occupation' => 'Trader',
        ])->assertCreated();

        $log = AuditLog::query()->where('action', AuditAction::GuarantorAdded->value)->sole();

        expect($log->after_json['name'])->toBe('Salma Kimaro')
            ->and($log->auditable_id)->toBe($customer->getKey());
    });

    it('records a guarantor being removed, with who they were', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        $guarantorId = $this->postJson("/api/v1/customers/{$customer->id}/guarantors", [
            'name' => 'Salma Kimaro',
            'phone' => '0755888111',
            'relationship' => 'friend',
            'address' => 'Kakonko',
            'occupation' => 'Trader',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/customers/{$customer->id}/guarantors/{$guarantorId}")->assertOk();

        $log = AuditLog::query()->where('action', AuditAction::GuarantorRemoved->value)->sole();

        /*
         * Snapshotted before the delete. §6 requires at least one guarantor
         * before a loan may progress, so removing one changes what a customer
         * is eligible for — and the row itself is gone by the time anyone asks
         * who it was.
         */
        expect($log->before_json['name'])->toBe('Salma Kimaro')
            ->and($log->before_json['phone'])->toBe('0755888111');
    });

    it('records next-of-kin being added and removed', function (): void {
        officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        $kinId = $this->postJson("/api/v1/customers/{$customer->id}/next-of-kin", [
            'name' => 'Joseph Mrema',
            'relationship' => 'sibling',
            'phone' => '0755888222',
            'address' => 'Kakonko',
        ])->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/customers/{$customer->id}/next-of-kin/{$kinId}")->assertOk();

        expect(AuditLog::query()->where('action', AuditAction::NextOfKinAdded->value)->exists())->toBeTrue();

        $removed = AuditLog::query()->where('action', AuditAction::NextOfKinRemoved->value)->sole();

        expect($removed->before_json['name'])->toBe('Joseph Mrema');
    });

    it('attributes the change to the officer who made it', function (): void {
        $officer = officerAt('Kakonko', RoleName::LoanOfficer);
        $customer = registeredCustomer();

        $this->postJson("/api/v1/customers/{$customer->id}/guarantors", [
            'name' => 'Salma Kimaro',
            'phone' => '0755888111',
            'relationship' => 'friend',
            'address' => 'Kakonko',
            'occupation' => 'Trader',
        ])->assertCreated();

        expect(AuditLog::query()->where('action', AuditAction::GuarantorAdded->value)->value('user_id'))
            ->toBe($officer->getKey());
    });
});
