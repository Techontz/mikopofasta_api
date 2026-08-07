<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Auth\Enums\RoleName;
use App\Domain\Auth\Enums\UserStatus;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Backend spec §2.1 — `users`.
 *
 * A user has exactly ONE role, held on `role_id`. That is the spec's model and
 * it is the authoritative column. Spatie's `model_has_roles` pivot is kept in
 * sync from it (see booted() below) so that Spatie's permission resolution,
 * middleware and Gate integration all work — but nothing should ever write to
 * that pivot directly.
 *
 * Permissions granted *directly* to a user (Spatie's `model_has_permissions`)
 * are the frontend's `extraPermissions`: grants that sit on top of the role.
 * This is how spec §13/§14 Decision 1 is represented — cross-branch loan
 * review is never implied by a role, only ever an explicit per-user grant.
 *
 * The property list below documents the runtime types after casting. Static
 * analysis infers column types from the migration, where `status` is an ENUM
 * (string) and `last_login_at` a timestamp (string); the casts() method turns
 * them into a UserStatus and a CarbonImmutable, which is what callers get.
 *
 * @property int $id
 * @property string $name
 * @property string $phone
 * @property string|null $email
 * @property string $password
 * @property int $role_id
 * @property int|null $branch_id
 * @property int|null $zone_id
 * @property int|null $region_id
 * @property UserStatus $status
 * @property CarbonImmutable|null $last_login_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 * @property-read Role $role
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * This service is token-authenticated and has no "remember me" cookie, so
     * the column does not exist. Blanking the name disables the framework's
     * remember-token handling cleanly rather than letting it query a missing
     * column.
     */
    protected $rememberTokenName = '';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'role_id',
        'branch_id',
        'zone_id',
        'region_id',
        'status',
        'last_login_at',
        'created_by',

        /*
         * The self-service half — see the 2026_08_17 migration. Fillable
         * because the profile endpoint writes them from a strict allowlist;
         * nothing organisational was added here, and nothing was moved out of
         * HR's control.
         */
        'photo_path',
        'emergency_contact_name', 'emergency_contact_phone', 'emergency_contact_relationship',
        'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship',
        'address', 'preferred_language', 'notification_preferences',
        /* Presentation preferences — see the 2026_08_26 migration. */
        'timezone', 'date_format', 'number_format', 'theme',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The user's home branch. Always assigned, including for HQ-wide roles —
     * they are based at the Head Office branch (spec §12 Decision 2). Whether
     * a user can see *other* branches is decided by the `branches.view_all`
     * permission, never by this being null.
     *
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Populated for the zone_manager role — the oversight scope, not the
     * branch they sit in (spec §13).
     *
     * @return BelongsTo<Zone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    /**
     * Populated for the regional_manager role (spec §13).
     *
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * The employment record HR maintains — employee number, salary,
     * employment status, hire date. Read-only from this side: the profile
     * endpoint never writes through it.
     *
     * @return HasOne<StaffProfile, $this>
     */
    public function staffProfile(): HasOne
    {
        return $this->hasOne(StaffProfile::class);
    }

    /**
     * Who this person reports to.
     *
     * Derived rather than stored: the only reporting edge this schema records
     * is `zones.zone_manager_id`, so a user in a zone reports to that zone's
     * manager and everybody else has no recorded supervisor. Inventing a
     * column here would be inventing an org chart.
     */
    public function supervisor(): ?User
    {
        $managerId = $this->zone?->zone_manager_id;

        if ($managerId === null || $managerId === $this->getKey()) {
            return null;
        }

        return User::query()->find($managerId);
    }

    public function roleName(): RoleName
    {
        return RoleName::from($this->role->name);
    }

    /**
     * Every permission this user effectively holds: the role's grants plus any
     * direct per-user grants. Mirrors the frontend's getEffectivePermissions().
     *
     * @return list<string>
     */
    public function effectivePermissionNames(): array
    {
        return array_values(array_unique(
            $this->getAllPermissions()->pluck('name')->all(),
        ));
    }

    /**
     * Grants held on top of the role — the frontend's `extraPermissions`.
     *
     * @return list<string>
     */
    public function extraPermissionNames(): array
    {
        return array_values($this->getDirectPermissions()->pluck('name')->all());
    }

    public function hasPermission(PermissionName $permission): bool
    {
        return $this->hasPermissionTo($permission->value);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role->name === RoleName::SuperAdmin->value;
    }

    public function canAuthenticate(): bool
    {
        return $this->status->canAuthenticate();
    }

    /**
     * The automation's account — not a person, and not administrable.
     *
     * Everything that manages users consults this: the account is hidden from
     * the user list, refused for edit, status change, deletion and password
     * reset, and cannot be created through the API. It exists so the books can
     * name who posted a scheduled entry, and for nothing else.
     */
    public function isSystemAccount(): bool
    {
        return $this->status === UserStatus::System;
    }

    /**
     * Real people only.
     *
     * Applied by user administration rather than as a global scope, because
     * SystemActor must still be able to find the account — a global scope would
     * hide it from the one caller whose whole purpose is to resolve it.
     *
     * @param Builder<User> $query
     * @return Builder<User>
     */
    public function scopeHumans(Builder $query): Builder
    {
        return $query->where('status', '!=', UserStatus::System->value);
    }

    /**
     * Two-letter avatar initials, matching the frontend's `avatarInitials`.
     */
    public function avatarInitials(): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', $this->name) ?: []));

        return implode('', array_map(
            static fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)),
            array_slice($parts, 0, 2),
        ));
    }

    /**
     * Keeps Spatie's role pivot consistent with the authoritative `role_id`.
     *
     * Doing this on the model rather than in an action means seeders, factories
     * and tinker sessions cannot produce a user whose pivot disagrees with the
     * column — there is no code path that bypasses it.
     */
    protected static function booted(): void
    {
        static::saved(function (User $user): void {
            if (! $user->wasRecentlyCreated && ! $user->wasChanged('role_id')) {
                return;
            }

            $role = Role::find($user->role_id);

            if ($role !== null) {
                $user->syncRoles([$role]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => UserStatus::class,
            'last_login_at' => 'datetime',
            'notification_preferences' => 'array',
        ];
    }
}
