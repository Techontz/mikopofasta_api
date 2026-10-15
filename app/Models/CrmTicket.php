<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * CRM case received from a customer and tracked until resolved: a complaint (something went wrong) or a customer request
 * (something the customer asks for — a statement, a change of details …), shown on Complaints and Customer Requests.
 */
class CrmTicket extends Model
{
    use Auditable;

    /**
     * @var array<string, string>
     */
    public const TYPES = [
        'complaint' => 'Complaint',
        'request' => 'Customer Request',
    ];

    /**
     * Categories offered for a new case (Customer Care dashboard "Customer Cases by Category").
     *
     * @var array<string, string>
     */
    public const CATEGORIES = [
        'payment_issue' => 'Payment Issue',
        'loan_inquiry' => 'Loan Inquiry',
        'account_update' => 'Account Update',
        'kyc_issue' => 'KYC Issue',
        'general_inquiry' => 'General Inquiry',
        'staff_conduct' => 'Staff Conduct',
        'feedback' => 'Feedback',
        'other' => 'Other',
    ];

    /**
     * Categories of cases recorded before the Customer Care categories, still shown by their label.
     *
     * @var array<string, string>
     */
    public const LEGACY_CATEGORIES = [
        'complaint' => 'Complaint',
        'inquiry' => 'General Inquiry',
        'request' => 'Request',
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
        'waiting' => 'WAITING',
        'resolved' => 'RESOLVED',
        'closed' => 'CLOSED',
    ];

    /**
     * Statuses of a case still being handled.
     *
     * @var list<string>
     */
    public const OPEN_STATUSES = ['open', 'in_progress', 'waiting'];

    public static function categoryLabel(string $category): string
    {
        return self::CATEGORIES[$category] ?? self::LEGACY_CATEGORIES[$category] ?? Str::headline($category);
    }

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
