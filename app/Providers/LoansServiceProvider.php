<?php

namespace App\Providers;

use App\Integrations\BankMandate\BankMandateGateway;
use App\Integrations\BankMandate\HttpBankMandateGateway;
use App\Integrations\BankMandate\TestBankMandateGateway;
use App\Integrations\Vodacom\HttpVodacomGateway;
use App\Integrations\Vodacom\TestVodacomGateway;
use App\Integrations\Vodacom\VodacomGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Loans module: binds the Vodacom (KYC + disbursement) and bank e-mandate connectors by configured driver.
 */
class LoansServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VodacomGateway::class, fn (): VodacomGateway => config('integrations.vodacom.driver') === 'test' ? new TestVodacomGateway : new HttpVodacomGateway);

        $this->app->bind(BankMandateGateway::class, fn (): BankMandateGateway => config('integrations.bank_mandate.driver') === 'test' ? new TestBankMandateGateway : new HttpBankMandateGateway);
    }
}
