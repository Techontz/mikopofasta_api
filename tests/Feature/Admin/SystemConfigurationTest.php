<?php

declare(strict_types=1);

use App\Domain\Auth\Enums\RoleName;
use App\Domain\Loans\Enums\InterestFormulaCode;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationTriggerEvent;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\InterestFormula;
use App\Models\Loan;
use App\Models\NotificationTemplate;
use App\Models\RepaymentSchedule;
use Database\Seeders\NotificationTemplateSeeder;

/**
 * Settings → Interest Formulas, Repayment Schedules, Notification Templates,
 * Audit Logs.
 *
 * See docs/modules/administration.md.
 */
beforeEach(function (): void {
    seedLoanFoundation();
    seedRbac();
});

/** Baraka Mushi — Admin: the only seeded role holding `admin.org_settings`. */
function actingAsAdmin(): App\Models\User
{
    return actingAsRole(RoleName::Admin);
}

/** @param array<string, mixed> $overrides */
function schedulePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Fortnightly',
        'code' => 'FORTNIGHTLY',
        'frequencyDays' => 14,
    ], $overrides);
}

/** @param array<string, mixed> $overrides */
function templatePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Overdue reminder',
        'triggerEvent' => NotificationTriggerEvent::PaymentOverdue->value,
        'channel' => NotificationChannel::Sms->value,
        'body' => 'Habari {{customer_name}}, deni la TZS {{amount_due}} limechelewa siku {{days_overdue}}.',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Interest formulas
// ---------------------------------------------------------------------------

describe('interest formulas', function (): void {
    it('lists the three the engine implements, with what each is carrying', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        $rows = $this->getJson('/api/v1/interest-formulas')->assertOk()->json('data');

        expect(array_column($rows, 'code'))
            ->toEqualCanonicalizing(array_map(
                static fn (InterestFormulaCode $c): string => $c->value,
                InterestFormulaCode::cases(),
            ));

        // The count is what makes an edit feel weighty on the settings screen.
        expect($rows[0])->toHaveKey('productCount');
    });

    it('renames a formula and rewrites its description', function (): void {
        actingAsAdmin();
        $formula = InterestFormula::query()->where('code', InterestFormulaCode::Reducing)->firstOrFail();

        $this->putJson("/api/v1/interest-formulas/{$formula->id}", [
            'name' => 'Reducing balance',
            'description' => 'Interest accrues on what is still owed, not on the original principal.',
        ])->assertOk()->assertJsonPath('data.name', 'Reducing balance');

        // The code is untouched — it is what the schedule generator branches on.
        expect($formula->fresh()->code)->toBe(InterestFormulaCode::Reducing);
    });

    it('records the rename in the audit trail', function (): void {
        $admin = actingAsAdmin();
        $formula = InterestFormula::query()->where('code', InterestFormulaCode::Flat)->firstOrFail();
        $before = $formula->name;

        $this->putJson("/api/v1/interest-formulas/{$formula->id}", [
            'name' => 'Flat rate',
            'description' => null,
        ])->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::InterestFormulaUpdated->value)
            ->where('auditable_id', $formula->id)
            ->sole();

        expect($log->user_id)->toBe($admin->id)
            ->and($log->before_json['name'])->toBe($before)
            ->and($log->after_json['name'])->toBe('Flat rate');
    });

    it('refuses a name another formula already has', function (): void {
        actingAsAdmin();
        $flat = InterestFormula::query()->where('code', InterestFormulaCode::Flat)->firstOrFail();
        $simple = InterestFormula::query()->where('code', InterestFormulaCode::Simple)->firstOrFail();

        $this->putJson("/api/v1/interest-formulas/{$flat->id}", [
            'name' => $simple->name,
            'description' => null,
        ])->assertUnprocessable();
    });

    /*
     * The absence is the point, so it is asserted rather than assumed. A fourth
     * formula would be a row the interest engine has no branch for: every loan
     * priced from a product using it would fail at origination.
     */
    it('exposes no way to create or delete a formula', function (): void {
        actingAsAdmin();
        $formula = InterestFormula::query()->firstOrFail();

        $this->postJson('/api/v1/interest-formulas', ['name' => 'Compound', 'code' => 'COMPOUND'])
            ->assertStatus(405);
        $this->deleteJson("/api/v1/interest-formulas/{$formula->id}")->assertStatus(405);
    });

    it('refuses the edit without admin.org_settings', function (): void {
        actingAsRole(RoleName::LoanOfficer);
        $formula = InterestFormula::query()->firstOrFail();

        $this->putJson("/api/v1/interest-formulas/{$formula->id}", ['name' => 'Anything'])
            ->assertForbidden();
    });
});

// ---------------------------------------------------------------------------
// Repayment schedules
// ---------------------------------------------------------------------------

describe('repayment schedules', function (): void {
    it('creates one, upper-casing the code', function (): void {
        actingAsAdmin();

        $this->postJson('/api/v1/repayment-schedules', schedulePayload(['code' => 'fortnightly']))
            ->assertCreated()
            ->assertJsonPath('data.code', 'FORTNIGHTLY')
            ->assertJsonPath('data.frequencyDays', 14);
    });

    it('refuses a code another schedule already has, whatever its case', function (): void {
        actingAsAdmin();
        $existing = RepaymentSchedule::query()->firstOrFail();

        $this->postJson('/api/v1/repayment-schedules', schedulePayload([
            'name' => 'Something else',
            'code' => mb_strtolower($existing->code),
        ]))->assertUnprocessable();
    });

    it('rejects a code with a space in it', function (): void {
        actingAsAdmin();

        // The code is a lookup key for seeders and product configuration; a
        // space in it makes every one of those references fragile.
        $this->postJson('/api/v1/repayment-schedules', schedulePayload(['code' => 'EVERY OTHER WEEK']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    });

    it('renames a schedule that loans are running on', function (): void {
        actingAsAdmin();
        $schedule = scheduleWithLoans();

        // The name is a label. Correcting it changes no arithmetic.
        $this->putJson("/api/v1/repayment-schedules/{$schedule->id}", schedulePayload([
            'name' => 'Every month',
            'code' => $schedule->code,
            'frequencyDays' => $schedule->frequency_days,
        ]))->assertOk()->assertJsonPath('data.name', 'Every month');
    });

    /*
     * The frequency is not a label: it is what generated every installment date
     * on every loan using the schedule. Changing it would leave those loans
     * with a cadence their own configuration no longer explains, and nothing
     * regenerates them.
     */
    it('refuses a frequency change once loans are running on it', function (): void {
        actingAsAdmin();
        $schedule = scheduleWithLoans();

        $this->putJson("/api/v1/repayment-schedules/{$schedule->id}", schedulePayload([
            'name' => $schedule->name,
            'code' => $schedule->code,
            'frequencyDays' => $schedule->frequency_days + 1,
        ]))->assertStatus(409);

        expect($schedule->fresh()->frequency_days)->toBe($schedule->frequency_days);
    });

    it('refuses to retire a schedule with loans on it', function (): void {
        actingAsAdmin();
        $schedule = scheduleWithLoans();

        $this->deleteJson("/api/v1/repayment-schedules/{$schedule->id}")->assertStatus(409);

        expect(RepaymentSchedule::query()->whereKey($schedule->id)->exists())->toBeTrue();
    });

    it('refuses to retire a schedule a product still offers', function (): void {
        actingAsAdmin();

        $schedule = RepaymentSchedule::query()
            ->whereHas('products')
            ->whereDoesntHave('loans')
            ->first();

        // Every seeded schedule may carry loans in this fixture; skip rather
        // than assert against a state the seeders do not produce.
        if ($schedule === null) {
            $this->markTestSkipped('No product-only schedule in the seeded set.');
        }

        $this->deleteJson("/api/v1/repayment-schedules/{$schedule->id}")->assertStatus(409);
    });

    it('retires one nothing is using, keeping the row', function (): void {
        actingAsAdmin();

        $id = $this->postJson('/api/v1/repayment-schedules', schedulePayload())
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/repayment-schedules/{$id}")->assertOk();

        // Soft-deleted, so a historical loan can still name what it ran on.
        expect(RepaymentSchedule::query()->whereKey($id)->exists())->toBeFalse()
            ->and(RepaymentSchedule::withTrashed()->whereKey($id)->exists())->toBeTrue();
    });

    it('refuses every write without admin.org_settings', function (): void {
        actingAsRole(RoleName::LoanOfficer);
        $schedule = RepaymentSchedule::query()->firstOrFail();

        $this->postJson('/api/v1/repayment-schedules', schedulePayload())->assertForbidden();
        $this->putJson("/api/v1/repayment-schedules/{$schedule->id}", schedulePayload())->assertForbidden();
        $this->deleteJson("/api/v1/repayment-schedules/{$schedule->id}")->assertForbidden();
    });
});

/** A schedule at least one loan is running on. */
function scheduleWithLoans(): RepaymentSchedule
{
    seedLedgerFoundation();
    seedLedgerActivity();
    actingAsAdmin();

    $id = Loan::query()->value('repayment_schedule_id');

    return RepaymentSchedule::query()->findOrFail($id);
}

// ---------------------------------------------------------------------------
// Notification templates
// ---------------------------------------------------------------------------

describe('notification templates', function (): void {
    it('seeds one SMS message per event, including the one the documents quote', function (): void {
        $this->seed(NotificationTemplateSeeder::class);
        actingAsRole(RoleName::LoanOfficer);

        $rows = $this->getJson('/api/v1/notification-templates')->assertOk()->json('data');

        expect(array_column($rows, 'triggerEvent'))
            ->toEqualCanonicalizing(NotificationTriggerEvent::values());

        // REPAYMENT OVERVIEW §1 Step 5, verbatim.
        $received = collect($rows)->firstWhere('triggerEvent', NotificationTriggerEvent::PaymentReceived->value);
        expect($received['body'])->toContain('Tumepokea malipo yako');
    });

    it('tells the editor which placeholders each event can fill', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        $meta = $this->getJson('/api/v1/notification-templates')->assertOk()->json('meta');

        $overdue = collect($meta['triggerEvents'])
            ->firstWhere('value', NotificationTriggerEvent::PaymentOverdue->value);

        expect($overdue['placeholders'])->toContain('days_overdue')
            // The server decides the vocabulary, so it is the one that says.
            ->and($overdue['placeholders'])->not->toContain('disbursed_amount');
    });

    it('creates a template and stamps who wrote it', function (): void {
        $admin = actingAsAdmin();

        $body = $this->postJson('/api/v1/notification-templates', templatePayload())
            ->assertCreated()
            ->assertJsonPath('data.active', true)
            ->json('data');

        expect($body['updatedBy'])->toBe((string) $admin->id)
            ->and($body['placeholdersUsed'])
            ->toEqualCanonicalizing(['customer_name', 'amount_due', 'days_overdue']);
    });

    /*
     * An unknown placeholder is not a small problem: it reaches the customer as
     * the literal text `{{amount}}`, and the only person able to prevent that
     * is the one writing the message.
     */
    it('refuses a placeholder the event cannot supply', function (): void {
        actingAsAdmin();

        $this->postJson('/api/v1/notification-templates', templatePayload([
            'body' => 'Habari {{customer_name}}, salio {{disbursed_amount}}.',
        ]))->assertUnprocessable();
    });

    it('refuses a subject on an SMS', function (): void {
        actingAsAdmin();

        $this->postJson('/api/v1/notification-templates', templatePayload(['subject' => 'Reminder']))
            ->assertUnprocessable();
    });

    it('accepts a subject on an email', function (): void {
        actingAsAdmin();

        $this->postJson('/api/v1/notification-templates', templatePayload([
            'channel' => NotificationChannel::Email->value,
            'subject' => 'Payment overdue',
        ]))->assertCreated()->assertJsonPath('data.subject', 'Payment overdue');
    });

    /*
     * Two active SMS templates for one event would leave the sender picking
     * arbitrarily — the customer gets whichever row came back first.
     */
    it('refuses a second active template for the same event and channel', function (): void {
        actingAsAdmin();

        $this->postJson('/api/v1/notification-templates', templatePayload())->assertCreated();

        $this->postJson('/api/v1/notification-templates', templatePayload(['name' => 'Second try']))
            ->assertUnprocessable();
    });

    it('allows any number of drafts beside the live one', function (): void {
        actingAsAdmin();

        $this->postJson('/api/v1/notification-templates', templatePayload())->assertCreated();

        foreach (['Draft A', 'Draft B'] as $name) {
            $this->postJson('/api/v1/notification-templates', templatePayload([
                'name' => $name,
                'active' => false,
            ]))->assertCreated();
        }

        expect(NotificationTemplate::query()
            ->where('trigger_event', NotificationTriggerEvent::PaymentOverdue)
            ->count())->toBe(3);
    });

    it('promotes a draft once the live one is stood down', function (): void {
        actingAsAdmin();

        $live = $this->postJson('/api/v1/notification-templates', templatePayload())
            ->assertCreated()->json('data.id');
        $draft = $this->postJson('/api/v1/notification-templates', templatePayload([
            'name' => 'Gentler wording',
            'active' => false,
        ]))->assertCreated()->json('data.id');

        $this->putJson("/api/v1/notification-templates/{$live}", templatePayload(['active' => false]))
            ->assertOk();
        $this->putJson("/api/v1/notification-templates/{$draft}", templatePayload([
            'name' => 'Gentler wording',
            'active' => true,
        ]))->assertOk()->assertJsonPath('data.active', true);
    });

    it('finds the one template that should fire for an event', function (): void {
        actingAsAdmin();
        $this->postJson('/api/v1/notification-templates', templatePayload())->assertCreated();

        $found = NotificationTemplate::forEvent(
            NotificationTriggerEvent::PaymentOverdue,
            NotificationChannel::Sms,
        );

        expect($found?->name)->toBe('Overdue reminder');

        // Null rather than the SMS one: a text is not an acceptable substitute
        // for an email nobody configured.
        expect(NotificationTemplate::forEvent(
            NotificationTriggerEvent::PaymentOverdue,
            NotificationChannel::Email,
        ))->toBeNull();
    });

    it('filters by event, channel and whether it is live', function (): void {
        actingAsAdmin();
        $this->postJson('/api/v1/notification-templates', templatePayload())->assertCreated();
        $this->postJson('/api/v1/notification-templates', templatePayload([
            'name' => 'Draft',
            'active' => false,
        ]))->assertCreated();

        expect($this->getJson('/api/v1/notification-templates?active=1')->assertOk()->json('data'))
            ->toHaveCount(1);

        expect($this->getJson(
            '/api/v1/notification-templates?trigger_event='.NotificationTriggerEvent::LoanClosed->value,
        )->assertOk()->json('data'))->toBeEmpty();
    });

    it('retires a template, keeping what customers were told', function (): void {
        actingAsAdmin();

        $id = $this->postJson('/api/v1/notification-templates', templatePayload())
            ->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/notification-templates/{$id}")->assertOk();

        expect(NotificationTemplate::withTrashed()->whereKey($id)->exists())->toBeTrue();

        // And the slot is free again — a retired row is not "active".
        $this->postJson('/api/v1/notification-templates', templatePayload())->assertCreated();
    });

    it('records every change in the audit trail', function (): void {
        actingAsAdmin();

        $id = $this->postJson('/api/v1/notification-templates', templatePayload())
            ->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/notification-templates/{$id}")->assertOk();

        expect(AuditLog::query()->whereIn('action', [
            AuditAction::NotificationTemplateCreated->value,
            AuditAction::NotificationTemplateDeleted->value,
        ])->count())->toBe(2);
    });

    it('refuses every write without admin.org_settings', function (): void {
        actingAsRole(RoleName::LoanOfficer);

        $this->postJson('/api/v1/notification-templates', templatePayload())->assertForbidden();
    });
});

// ---------------------------------------------------------------------------
// Audit trail
// ---------------------------------------------------------------------------

describe('audit logs', function (): void {
    it('lists newest first with what the actor did', function (): void {
        actingAsAdmin();
        $schedule = RepaymentSchedule::query()->firstOrFail();

        $this->putJson("/api/v1/repayment-schedules/{$schedule->id}", schedulePayload([
            'name' => 'Renamed',
            'code' => $schedule->code,
            'frequencyDays' => $schedule->frequency_days,
        ]))->assertOk();

        $rows = $this->getJson('/api/v1/audit-logs')->assertOk()->json('data');

        expect($rows[0]['action'])->toBe(AuditAction::RepaymentScheduleUpdated->value)
            // The class for traceability, the short name for the Record column.
            ->and($rows[0]['auditableType'])->toBe(RepaymentSchedule::class)
            ->and($rows[0]['auditableLabel'])->toBe('RepaymentSchedule')
            ->and($rows[0]['userName'])->not->toBeNull();
    });

    it('offers the actions actually present as filter options', function (): void {
        actingAsAdmin();
        $formula = InterestFormula::query()->firstOrFail();
        $this->putJson("/api/v1/interest-formulas/{$formula->id}", ['name' => 'Renamed'])->assertOk();

        $meta = $this->getJson('/api/v1/audit-logs')->assertOk()->json('meta');

        expect($meta['actions'])->toContain(AuditAction::InterestFormulaUpdated->value)
            ->and($meta['pagination'])->toHaveKeys(['page', 'perPage', 'total', 'lastPage']);
    });

    it('filters by action, actor and record', function (): void {
        $admin = actingAsAdmin();
        $formula = InterestFormula::query()->firstOrFail();
        $schedule = RepaymentSchedule::query()->firstOrFail();

        $this->putJson("/api/v1/interest-formulas/{$formula->id}", ['name' => 'Renamed formula'])->assertOk();
        $this->putJson("/api/v1/repayment-schedules/{$schedule->id}", schedulePayload([
            'name' => 'Renamed schedule',
            'code' => $schedule->code,
            'frequencyDays' => $schedule->frequency_days,
        ]))->assertOk();

        expect($this->getJson('/api/v1/audit-logs?action='.AuditAction::InterestFormulaUpdated->value)
            ->assertOk()->json('data'))->toHaveCount(1);

        expect($this->getJson("/api/v1/audit-logs?user_id={$admin->id}")->assertOk()->json('data'))
            ->toHaveCount(2);

        // The short name works as well as the namespace: someone filtering from
        // what the screen shows should not have to know where the class lives.
        expect($this->getJson('/api/v1/audit-logs?auditable_type=InterestFormula')
            ->assertOk()->json('data'))->toHaveCount(1);
    });

    it('searches the action, the record type and the actor', function (): void {
        $admin = actingAsAdmin();
        $formula = InterestFormula::query()->firstOrFail();
        $this->putJson("/api/v1/interest-formulas/{$formula->id}", ['name' => 'Renamed'])->assertOk();

        expect($this->getJson('/api/v1/audit-logs?search=interest_formula')->assertOk()->json('data'))
            ->not->toBeEmpty();
        expect($this->getJson('/api/v1/audit-logs?search='.urlencode($admin->name))->assertOk()->json('data'))
            ->not->toBeEmpty();
    });

    it('rejects a range that ends before it starts', function (): void {
        actingAsAdmin();

        $this->getJson('/api/v1/audit-logs?from=2026-03-01&to=2026-02-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    });

    /*
     * The audit trail reveals more than any single screen it summarises —
     * salary figures, identity changes, every approval — so `audit.view` exists
     * to be granted to an auditor without granting the ability to change
     * anything.
     */
    it('opens to an auditor, who cannot change a setting', function (): void {
        actingAsRole(RoleName::Auditor);

        $this->getJson('/api/v1/audit-logs')->assertOk();
        $this->postJson('/api/v1/repayment-schedules', schedulePayload())->assertForbidden();
    });

    it('is closed to a role holding neither grant', function (): void {
        actingAsRole(RoleName::Teller);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    });

    /*
     * A trail pinned to one record is a different question from the whole
     * trail. The panel on a loan's detail page shows that loan's own history —
     * who approved it, when it was disbursed — which is what the rest of the
     * page already says. Requiring the global grant would hide a loan's history
     * from the officer working the loan.
     */
    it('lets someone who may view a record read that record’s history', function (): void {
        seedLedgerFoundation();
        seedLedgerActivity();

        $loan = Loan::query()->firstOrFail();
        officerAt(App\Models\Branch::query()->whereKey($loan->branch_id)->value('name'));

        // The whole trail is still refused: the officer holds neither grant.
        $this->getJson('/api/v1/audit-logs')->assertForbidden();

        $this->getJson('/api/v1/audit-logs?auditable_type=Loan&auditable_id='.$loan->id)->assertOk();
    });

    it('refuses a pinned read of a record the viewer may not see', function (): void {
        seedLedgerFoundation();
        seedLedgerActivity();

        // A loan at another branch. §13 scopes an officer to their own, and the
        // loan's own policy is what answers here.
        $loan = Loan::query()
            ->whereNot('branch_id', App\Models\Branch::query()->where('name', 'Kakonko')->value('id'))
            ->first();

        if ($loan === null) {
            $this->markTestSkipped('The seeded book has loans at one branch only.');
        }

        officerAt('Kakonko');

        $this->getJson('/api/v1/audit-logs?auditable_type=Loan&auditable_id='.$loan->id)
            ->assertForbidden();
    });

    /*
     * The types are enumerated rather than resolved from the string. Letting a
     * caller name any class would turn the pinned read into a way to probe for
     * models with permissive policies.
     */
    it('does not accept an arbitrary class as a way in', function (): void {
        seedLedgerFoundation();
        seedLedgerActivity();
        officerAt();

        $this->getJson('/api/v1/audit-logs?auditable_type=PayrollRun&auditable_id=1')
            ->assertForbidden();
    });

    /*
     * Append-only, in the strongest sense the router can express: there is no
     * write route for the audit trail anywhere, because an endpoint that could
     * rewrite a row would defeat the only thing the trail is for.
     */
    it('exposes no way to write to it', function (): void {
        actingAsAdmin();

        // Something has to be in the trail before "you cannot edit a row" means
        // anything — an empty table would pass this by having nothing to target.
        $formula = InterestFormula::query()->firstOrFail();
        $this->putJson("/api/v1/interest-formulas/{$formula->id}", ['name' => 'Renamed'])->assertOk();

        $log = AuditLog::query()->firstOrFail();

        // 405 on the collection, which exists but answers only GET.
        $this->postJson('/api/v1/audit-logs', [])->assertStatus(405);

        // 404 on a single row, because that URI is not routed at all — there is
        // no show, no update and no destroy for an audit entry.
        $this->putJson("/api/v1/audit-logs/{$log->id}", [])->assertNotFound();
        $this->deleteJson("/api/v1/audit-logs/{$log->id}")->assertNotFound();
        $this->getJson("/api/v1/audit-logs/{$log->id}")->assertNotFound();
    });
});
