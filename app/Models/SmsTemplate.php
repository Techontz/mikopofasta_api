<?php

namespace App\Models;

use App\Services\Sms\SmsTemplates;
use Illuminate\Database\Eloquent\Model;

/**
 * SMS centre message: an automatic template (key set: payment_received, repayment_reminder, overdue_reminder — sent by the
 * system, see {@see SmsTemplates}) or an announcement draft (key null) that staff send by hand.
 */
class SmsTemplate extends Model
{
    public const TYPE_AUTOMATIC = 'automatic';

    public const TYPE_ANNOUNCEMENT = 'announcement';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'days' => 'integer',
        ];
    }
}
