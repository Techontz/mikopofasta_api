<?php

namespace App\Integrations\Vodacom;

use App\Models\LoanDisbursement;
use Illuminate\Http\Request;

/**
 * Vodacom (M-Pesa) connector used by the loan lifecycle: subscriber KYC name lookup before credit
 * approval and B2C disbursement requests whose result arrives on a signed callback.
 * Selected by config('integrations.vodacom.driver').
 */
interface VodacomGateway
{
    /**
     * Registered subscriber name for a phone number. $nameOnFile is only used by the test driver.
     */
    public function kycLookup(string $phone, string $nameOnFile): TelcoKycResult;

    /**
     * Send a disbursement batch to Vodacom. The final result is delivered to the callback.
     */
    public function requestDisbursement(LoanDisbursement $disbursement): DisbursementRequestResult;

    /**
     * Whether a callback request carries a valid signature.
     */
    public function verifyCallback(Request $request): bool;

    public function parseCallback(Request $request): DisbursementCallback;

    /**
     * Portal where Finance completes the disbursement (Documents: "MANUAL VODACOM PORTAL").
     */
    public function portalUrl(): ?string;
}
