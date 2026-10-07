<?php

namespace Tests\Feature\Api\Sms;

use App\Enums\LoanStatus;
use App\Integrations\Payments\TestPaymentWebhookConnector;
use App\Integrations\Sms\LogSmsGateway;
use App\Integrations\Sms\SmsGateway;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Group;
use App\Models\SmsLog;
use App\Models\SmsTemplate;
use App\Services\Sms\SmsTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Payments\InteractsWithRepayments;
use Tests\TestCase;

/**
 * SMS Centre: automatic templates (payment receipt, repayment and overdue reminders), announcement drafts, contact groups
 * and announcements sent by hand to customers, a contact group or typed numbers.
 */
class SmsCentreApiTest extends TestCase
{
    use InteractsWithRepayments, RefreshDatabase;

    private LogSmsGateway $sms;

    private Employee $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new LogSmsGateway;
        $this->app->instance(SmsGateway::class, $this->sms);
        $this->admin = $this->signInAdmin();
    }

    public function test_the_payment_receipt_uses_the_companys_wording_and_can_be_switched_off(): void
    {
        $templates = $this->getJson('/api/v1/sms/templates')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame([SmsTemplates::PAYMENT_RECEIVED, SmsTemplates::REPAYMENT_REMINDER, SmsTemplates::OVERDUE_REMINDER], array_column($templates, 'key'));
        $payment = $templates[0];

        $this->putJson("/api/v1/sms/templates/{$payment['id']}", ['body' => 'Asante {name}! Rejesho TSH {amount} limepokelewa, salio TSH {balance}. Risiti {receipt}', 'is_active' => true])->assertOk();
        $loan = $this->activeLoan($this->admin, customer: ['first_name' => 'Juma']);
        $this->webhook(['reference' => $loan->loan_number, 'amount' => 30000, 'channel' => 'VODACOM', 'transaction_id' => 'TX-SMS-1'])->assertOk();

        $log = SmsLog::where('customer_id', $loan->customer_id)->sole();
        $this->assertSame('payment', $log->category);
        $this->assertSame('sent', $log->status);
        $this->assertStringStartsWith('Asante Juma! Rejesho TSH 30,000 limepokelewa, salio TSH 100,000. Risiti RC', $log->message);

        $this->putJson("/api/v1/sms/templates/{$payment['id']}", ['body' => 'x', 'is_active' => false])->assertOk();
        $this->webhook(['reference' => $loan->loan_number, 'amount' => 10000, 'channel' => 'VODACOM', 'transaction_id' => 'TX-SMS-2'])->assertOk();
        $this->assertSame(1, SmsLog::where('customer_id', $loan->customer_id)->count());

        $this->deleteJson("/api/v1/sms/templates/{$payment['id']}")->assertStatus(422);
        $this->putJson("/api/v1/sms/templates/{$payment['id']}", ['body' => 'x', 'is_active' => true, 'days' => 2])->assertUnprocessable()->assertJsonValidationErrors('days');
    }

    public function test_reminders_go_out_before_and_after_the_due_date_once_each(): void
    {
        $loan = $this->activeLoan($this->admin, customer: ['first_name' => 'Asha']);
        $schedule = $loan->schedules()->sole();
        $schedule->update(['due_date' => today()->addDays(3)]);
        $reminder = app(SmsTemplates::class)->template($this->admin->company_id, SmsTemplates::REPAYMENT_REMINDER);
        $this->putJson("/api/v1/sms/templates/{$reminder->id}", ['body' => 'Habari {name}, lipa TSH {amount} tarehe {due_date}.', 'is_active' => true, 'days' => 3])->assertOk();

        $this->artisan('sms:send-reminders')->assertSuccessful();
        $this->artisan('sms:send-reminders')->assertSuccessful();

        $sent = SmsLog::where('category', 'reminder')->sole();
        $this->assertSame('Habari Asha, lipa TSH 130,000 tarehe '.today()->addDays(3)->format('d/m/Y').'.', $sent->message);

        $schedule->update(['due_date' => today()->subDay(), 'paid_amount' => 30000]);
        $loan->update(['status' => LoanStatus::Overdue]);
        $this->artisan('sms:send-reminders')->assertSuccessful();
        $overdue = SmsLog::where('category', 'overdue')->sole();
        $this->assertStringContainsString('TSH 100,000', $overdue->message);

        $schedule->update(['paid_amount' => 130000, 'due_date' => today()->addDays(3)]);
        SmsLog::query()->delete();
        $this->artisan('sms:send-reminders')->assertSuccessful();
        $this->assertSame(0, SmsLog::count(), 'A fully paid instalment gets no reminder.');
    }

    public function test_announcement_to_filtered_customers_after_a_preview(): void
    {
        $group = Group::create(['company_id' => $this->admin->company_id, 'name' => 'Wajasiriamali']);
        $inGroup = Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'group_id' => $group->id, 'first_name' => 'Neema', 'status' => 'open']);
        Customer::factory()->create(['company_id' => $this->admin->company_id, 'branch_id' => $this->admin->branch_id, 'status' => 'open']);
        $draft = $this->postJson('/api/v1/sms/templates', ['name' => 'Sikukuu', 'body' => 'Heri ya sikukuu {name}! - {company}'])->assertCreated()->json('data');
        $this->assertSame(SmsTemplate::TYPE_ANNOUNCEMENT, $draft['type']);

        $payload = ['audience' => 'customers', 'branch_id' => 'all', 'customer_status' => 'open', 'group_id' => $group->id, 'loan_status' => 'any', 'message' => $draft['body']];
        $this->postJson('/api/v1/sms/preview', $payload)->assertOk()
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.sample', 'Heri ya sikukuu Neema! - '.$this->admin->company->name);

        $this->postJson('/api/v1/sms/send', $payload)->assertCreated()->assertJsonPath('count', 1);

        $log = SmsLog::where('category', 'announcement')->sole();
        $this->assertSame($inGroup->id, $log->customer_id);
        $this->assertSame('sent', $log->status, 'Delivered right after the response.');
        $this->assertSame($this->admin->id, $log->employee_id);
        $this->assertCount(1, $this->sms->sent);

        $this->getJson('/api/v1/sms/logs?category=announcement')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer', $inGroup->full_name);
    }

    public function test_contact_groups_and_typed_numbers(): void
    {
        $this->postJson('/api/v1/sms/contact-groups', ['name' => 'Mawakala', 'members' => [['name' => 'Ali', 'phone' => '0712 345 678'], ['phone' => 'abc']]])
            ->assertUnprocessable()->assertJsonValidationErrors('members.1.phone');

        $group = $this->postJson('/api/v1/sms/contact-groups', ['name' => 'Mawakala', 'members' => [['name' => 'Ali', 'phone' => '0712 345 678'], ['name' => null, 'phone' => '+255 754 000 111'], ['phone' => '255712345678']]])
            ->assertCreated()->assertJsonCount(2, 'data.members')->json('data');
        $this->assertSame('255712345678', $group['members'][0]['phone']);

        $this->postJson('/api/v1/sms/send', ['audience' => 'contact_group', 'contact_group_id' => $group['id'], 'message' => 'Habari {name}, mkutano kesho.'])->assertCreated()->assertJsonPath('count', 2);
        $this->assertEqualsCanonicalizing(['Habari Ali, mkutano kesho.', 'Habari Mteja, mkutano kesho.'], array_column($this->sms->sent, 'message'));

        $this->postJson('/api/v1/sms/send', ['audience' => 'numbers', 'numbers' => "0655111222, 655111222\nhello", 'message' => 'Test'])
            ->assertCreated()->assertJsonPath('count', 1)->assertJsonPath('invalid', 1);
        $this->postJson('/api/v1/sms/send', ['audience' => 'numbers', 'numbers' => 'hello', 'message' => 'Test'])->assertUnprocessable()->assertJsonValidationErrors('audience');
    }

    public function test_only_staff_with_the_sms_permission_use_the_sms_centre(): void
    {
        $officer = $this->employeeWithRole($this->admin, 'loan_officer');
        $this->actingAs($officer);

        $this->getJson('/api/v1/sms/templates')->assertForbidden();
        $this->postJson('/api/v1/sms/send', ['audience' => 'numbers', 'numbers' => '0712345678', 'message' => 'x'])->assertForbidden();
        $this->getJson('/api/v1/sms/contact-groups')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function webhook(array $payload): TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/webhooks/payments', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, TestPaymentWebhookConnector::DEFAULT_SECRET),
        ], $body);
    }
}
