<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * The authenticated user's own profile.
 *
 * Split into two blocks that mean different things, because the page has to
 * show which is which:
 *
 *   - `editable` — what this person maintains about themselves.
 *   - `readOnly` — what the organisation has decided about them. Shown because
 *     an employee should be able to see their own record, never writable here.
 *
 * The shape is deliberately explicit about absence. Where the system holds no
 * value the field is null and the UI says so, rather than the resource
 * inventing one: this business records no department or position for staff
 * anywhere, so neither appears — a blank labelled "Department" would imply the
 * data exists and is merely missing for this person.
 *
 * @mixin User
 */
final class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $staff = $this->staffProfile;
        $supervisor = $this->supervisor();

        return [
            'id' => (string) $this->id,

            /* ---------------------------------------------- identity */
            'name' => $this->name,
            'username' => $this->phone,
            'photoUrl' => $this->photo_path === null
                ? null
                : URL::temporarySignedRoute(
                    'api.v1.users.photo',
                    now()->addMinutes(KycDocumentStorage::URL_TTL_MINUTES),
                    ['user' => $this->id],
                ),

            /* ------------------------------------------- self-service */
            'editable' => [
                'phone' => $this->phone,
                'email' => $this->email,
                'address' => $this->address,
                'emergencyContactName' => $this->emergency_contact_name,
                'emergencyContactPhone' => $this->emergency_contact_phone,
                'emergencyContactRelationship' => $this->emergency_contact_relationship,
                'nextOfKinName' => $this->next_of_kin_name,
                'nextOfKinPhone' => $this->next_of_kin_phone,
                'nextOfKinRelationship' => $this->next_of_kin_relationship,
                'preferredLanguage' => $this->preferred_language,
                'notificationPreferences' => $this->notification_preferences ?? [
                    'sms' => true, 'email' => true, 'inApp' => true,
                ],
                /* Null means "follow the system default" — a user who never
                   opens Preferences behaves exactly as they did before it
                   existed. */
                'timezone' => $this->timezone,
                'dateFormat' => $this->date_format,
                'numberFormat' => $this->number_format,
                'theme' => $this->theme,
            ],

            /* ---------------------------- decided by the organisation */
            'readOnly' => [
                'employeeNumber' => $staff?->employee_number,
                'staffId' => $staff === null ? null : (string) $staff->getKey(),
                'branch' => $this->branch?->name,
                'zone' => $this->zone?->name,
                'region' => $this->region?->name,
                'role' => $this->role?->name,
                'employmentStatus' => $staff?->employment_status?->value,
                'hiredAt' => $staff?->hired_at?->toDateString(),
                /*
                 * Minor units, like every amount in this system. Visible to
                 * the person it belongs to and writable by nobody here.
                 *
                 * Cast explicitly: the column is a DECIMAL and PDO hands
                 * decimals back as strings, so an uncast value reaches the
                 * client as "450000.00" where the contract says a number.
                 * Done here rather than by adding a model cast, which would
                 * change what every existing HR endpoint returns.
                 */
                'baseSalary' => $staff?->base_salary === null ? null : (int) $staff->base_salary,
                'paymentMethod' => $staff?->payment_method?->value,
                'commissionEligible' => $staff === null ? null : (bool) $staff->commission_eligible,
                'supervisor' => $supervisor?->name,
                'userStatus' => $this->status->value,
                'createdAt' => $this->created_at?->toIso8601String(),
                'lastLoginAt' => $this->last_login_at?->toIso8601String(),
            ],

            /* Effective grants — role ∪ per-user, already resolved. Listed so
               a person can see what they may do, and see that they cannot
               change it. */
            'permissions' => $this->effectivePermissionNames(),
        ];
    }
}
