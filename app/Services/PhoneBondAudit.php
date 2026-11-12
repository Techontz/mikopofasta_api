<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\PhoneBondDevice;

/**
 * Append-only audit entries for eSIMU operations. Each one carries who acted (null for the system), the action, the
 * details (reason, result, Linda command reference) and, in its context, the customer, device and loan concerned.
 */
class PhoneBondAudit
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function record(PhoneBondDevice $device, string $action, ?Employee $actor, array $details, ?int $loanId = null): void
    {
        AuditLog::create([
            'company_id' => $device->company_id,
            'employee_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $device->getMorphClass(),
            'auditable_id' => $device->id,
            'after' => $details,
            'context' => [
                'customer_id' => $device->customer_id,
                'device_id' => $device->id,
                'imei' => $device->imei,
                'loan_id' => $loanId ?? $device->loan_id,
                'linda_device_id' => $device->linda_device_id,
            ],
            'ip_address' => request()?->ip(),
        ]);
    }
}
