<?php

namespace Tests\Feature\Api\Sms;

use App\Integrations\Sms\MessagingServiceGateway;
use App\Integrations\Sms\SmsGateway;
use App\Models\SmsLog;
use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * messaging-service.co.tz driver: Basic auth, {from, to, text} to the single-text endpoint (or the free test endpoint),
 * REJECTED statuses recorded as failed, SMS balance.
 */
class MessagingServiceGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['integrations.sms' => [
            'driver' => 'messaging_service', 'sender_id' => 'MKOPA', 'base_url' => 'https://messaging-service.co.tz',
            'username' => 'aladdin', 'password' => 'open sesame', 'test_mode' => false,
        ]]);
        $this->app->forgetInstance(SmsGateway::class);
    }

    public function test_sends_with_basic_auth_to_the_live_or_test_endpoint(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['to' => '255712345678', 'status' => ['groupId' => 18, 'groupName' => 'PENDING', 'id' => 51, 'name' => 'ENROUTE']]]])]);

        (new MessagingServiceGateway)->send('0712 345 678', 'Habari');
        config(['integrations.sms.test_mode' => true]);
        (new MessagingServiceGateway)->send('255712345678', 'Test');

        $sent = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
        $this->assertSame('https://messaging-service.co.tz/api/sms/v1/text/single', $sent[0]->url());
        $this->assertSame('https://messaging-service.co.tz/api/sms/v1/test/text/single', $sent[1]->url());
        $this->assertSame('Basic '.base64_encode('aladdin:open sesame'), $sent[0]->header('Authorization')[0]);
        $this->assertSame(['from' => 'MKOPA', 'to' => '255712345678', 'text' => 'Habari'], $sent[0]->data());
    }

    public function test_a_rejected_message_is_logged_as_failed_with_the_reason(): void
    {
        $admin = $this->signInAdmin();
        Http::fake(['*' => Http::response(['messages' => [['status' => ['groupId' => 19, 'groupName' => 'REJECTED', 'id' => 57, 'name' => 'REJECTED_NOT_ENOUGH_CREDITS', 'description' => 'Not enough credits']]]])]);

        $log = app(SmsSender::class)->send($admin->company_id, '0712345678', 'Habari', 'announcement');

        $this->assertSame('failed', $log->status);
        $this->assertSame('REJECTED_NOT_ENOUGH_CREDITS - Not enough credits', SmsLog::find($log->id)->error);
    }

    public function test_the_sms_centre_shows_the_balance(): void
    {
        $this->signInAdmin();
        Http::fake(['*/api/sms/v1/balance' => Http::response(['sms_balance' => 4520])]);

        $this->getJson('/api/v1/sms/balance')->assertOk()->assertJsonPath('data.balance', 4520)->assertJsonPath('data.error', null);
    }
}
