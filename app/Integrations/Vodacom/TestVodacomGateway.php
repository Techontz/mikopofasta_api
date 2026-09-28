<?php

namespace App\Integrations\Vodacom;

use App\Models\LoanDisbursement;

/**
 * Deterministic test driver.
 * KYC: the registered name equals the name on file, except phones ending in "0000" (registered to
 * "UNKNOWN SUBSCRIBER" → name mismatch) and "9999" (not registered).
 * Disbursement: config('integrations.vodacom.test_outcome') — "success" (default) and "failed" simulate the
 * callback immediately, "callback" leaves the batch waiting for a signed callback. Phones ending in "1111"
 * always fail ("Salio halitoshi").
 * Callbacks: HMAC like the real driver, with the fixed secret "test-vodacom-secret" when none is configured.
 */
class TestVodacomGateway extends HttpVodacomGateway
{
    public const DEFAULT_SECRET = 'test-vodacom-secret';

    public function kycLookup(string $phone, string $nameOnFile): TelcoKycResult
    {
        if (str_ends_with($phone, '9999')) {
            return new TelcoKycResult(false, null, 'Phone number is not registered on Vodacom');
        }

        return new TelcoKycResult(true, str_ends_with($phone, '0000') ? 'UNKNOWN SUBSCRIBER' : strtoupper($nameOnFile));
    }

    public function requestDisbursement(LoanDisbursement $disbursement): DisbursementRequestResult
    {
        $reference = 'VTX'.strtoupper(substr(sha1($disbursement->batch_id), 0, 10));

        if (str_ends_with((string) $disbursement->phone, '1111')) {
            return new DisbursementRequestResult(true, $reference, 'Salio halitoshi', false);
        }

        return match (config('integrations.vodacom.test_outcome', 'success')) {
            'failed' => new DisbursementRequestResult(true, $reference, 'Salio halitoshi', false),
            'callback' => new DisbursementRequestResult(true, $reference),
            default => new DisbursementRequestResult(true, $reference, null, true),
        };
    }

    public function portalUrl(): ?string
    {
        return parent::portalUrl();
    }

    protected function secret(): ?string
    {
        return parent::secret() ?? self::DEFAULT_SECRET;
    }
}
