<?php

namespace App\Integrations\Sms;

/**
 * An SMS provider that can tell how many SMS credits the account has left.
 */
interface ReportsSmsBalance
{
    public function balance(): ?int;
}
