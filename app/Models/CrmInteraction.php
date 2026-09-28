<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer contact recorded in CRM: call (incoming/outgoing), SMS, visit or note, with an optional follow-up.
 */
class CrmInteraction extends Model
{
    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'call' => 'CALL',
        'sms' => 'SMS',
        'visit' => 'VISIT',
        'note' => 'NOTE',
    ];

    /**
     * @var array<string, string>
     */
    public const DIRECTIONS = [
        'outgoing' => 'OUTGOING',
        'incoming' => 'INCOMING',
    ];

    /**
     * Call outcomes.
     *
     * @var array<string, string>
     */
    public const OUTCOMES = [
        'answered' => 'Answered',
        'promised_to_pay' => 'Promised to pay',
        'no_answer' => 'No answer',
        'busy' => 'Busy',
        'not_reachable' => 'Not reachable',
        'wrong_number' => 'Wrong number',
        'call_back' => 'Call back later',
        'refused' => 'Refused to pay',
        'inquiry' => 'Inquiry',
        'complaint' => 'Complaint',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'follow_up_date' => 'date',
            'follow_up_done_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function smsLog(): BelongsTo
    {
        return $this->belongsTo(SmsLog::class);
    }

    public function followUpDoneBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'follow_up_done_by');
    }
}
