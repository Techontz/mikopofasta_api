<?php

namespace App\Console\Commands;

use App\Services\PhoneBond;
use App\Services\Sms\SmsSender;
use App\Services\Sms\SmsTemplates;
use Illuminate\Console\Command;

/**
 * eSIMU, the evening of a due date in local time: warn customers whose instalment due today is not fully paid that the
 * phone may be locked the next morning (SMS template esimu_lock_warning, once per instalment).
 */
class WarnPhoneBond extends Command
{
    protected $signature = 'phone-bond:warn';

    protected $description = 'Send the eSIMU evening lock warning for instalments due today and not fully paid';

    public function handle(PhoneBond $phoneBond, SmsTemplates $templates, SmsSender $sender): int
    {
        $this->info('Warnings sent: '.$phoneBond->warn(PhoneBond::today(), $templates, $sender).'.');

        return self::SUCCESS;
    }
}
