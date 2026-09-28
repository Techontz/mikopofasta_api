<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Customer report / complaint received by staff and tracked until resolved.
 */
class CrmTicket extends Model
{
    use Auditable;

    /**
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'complaint' => 'Complaint',
        'inquiry' => 'Inquiry',
        'request' => 'Request',
        'payment_issue' => 'Payment issue',
        'staff_conduct' => 'Staff conduct',
        'feedback' => 'Feedback',
    ];

    /**
     * @var array<string, string>
     */
    public const CHANNELS = [
        'call' => 'Call',
        'sms' => 'SMS',
        'walk_in' => 'Walk in',
        'field' => 'Field visit',
    ];

    /**
     * @var array<string, string>
     */
    public const PRIORITIES = [
        'low' => 'LOW',
        'normal' => 'NORMAL',
        'high' => 'HIGH',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUSES = [
        'open' => 'OPEN',
        'in_progress' => 'IN PROGRESS',
        'resolved' => 'RESOLVED',
        'closed' => 'CLOSED',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (CrmTicket $ticket): void {
            $ticket->ticket_number ??= 'TMP-'.Str::uuid();
        });

        static::created(function (CrmTicket $ticket): void {
            if ($ticket->ticket_number === null || str_starts_with($ticket->ticket_number, 'TMP-')) {
                $ticket->forceFill(['ticket_number' => 'CRM'.str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_to');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }
}
