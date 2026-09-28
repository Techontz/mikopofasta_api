<?php

namespace App\Providers;

use App\Integrations\Face\FaceVerifier;
use App\Integrations\Face\HttpFaceVerifier;
use App\Integrations\Face\TestFaceVerifier;
use App\Integrations\Nida\HttpNidaConnector;
use App\Integrations\Nida\NidaConnector;
use App\Integrations\Nida\TestNidaConnector;
use App\Integrations\Sms\HttpSmsGateway;
use App\Integrations\Sms\LogSmsGateway;
use App\Integrations\Sms\SmsGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Customers / KYC module: binds the NIDA, face liveness and SMS connectors by configured driver.
 */
class CustomersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(NidaConnector::class, fn (): NidaConnector => config('integrations.nida.driver') === 'test' ? new TestNidaConnector : new HttpNidaConnector);

        $this->app->bindIf(FaceVerifier::class, fn (): FaceVerifier => config('integrations.face.driver') === 'test' ? new TestFaceVerifier : new HttpFaceVerifier);

        $this->app->singletonIf(SmsGateway::class, fn (): SmsGateway => in_array(config('integrations.sms.driver'), ['log', 'test'], true) ? new LogSmsGateway : new HttpSmsGateway);
    }
}
