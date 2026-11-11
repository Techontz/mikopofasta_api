<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\PhoneBondDevice;
use App\Notifications\CustomerActivity;
use Illuminate\Support\Facades\Notification;

/**
 * Header-bell alerts for eSIMU: sent to active staff holding a permission who can see the device's branch (never to the
 * actor). A failure here never blocks the action that raised it.
 */
class PhoneBondAlerts
{
    public function __construct(private AccessControl $access) {}

    public function toStaff(PhoneBondDevice $device, string $permission, string $kind, string $title, string $message, ?Employee $actor = null): void
    {
        rescue(function () use ($device, $permission, $kind, $title, $message, $actor): void {
            $recipients = Employee::query()
                ->where('company_id', $device->company_id)
                ->where('status', 'active')
                ->when($actor !== null, fn ($query) => $query->whereKeyNot($actor->getKey()))
                ->with(['role.permissions', 'permissionOverrides', 'shareHolder'])
                ->get()
                ->reject(fn (Employee $employee): bool => $employee->isShareholderAccount())
                ->filter(function (Employee $employee) use ($device, $permission): bool {
                    $branches = $this->access->branchIds($employee);

                    return ($branches === null || in_array((int) $device->branch_id, $branches, true)) && $this->access->can($employee, $permission);
                });

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new CustomerActivity([
                    'kind' => $kind,
                    'title' => $title,
                    'message' => $message,
                    'link' => '/esimu/devices?device='.$device->id,
                    'customer_id' => (int) $device->customer_id,
                ]));
            }
        });
    }
}
