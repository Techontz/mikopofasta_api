<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\KycStatus;
use App\Domain\Customers\Enums\MaritalStatus;
use App\Domain\Customers\Enums\ResidenceType;
use App\Enums\FreezableType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Backend spec §2.4 — `customers`.
 *
 * @property int $id
 * @property string $customer_number
 * @property string $nida_number
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property CarbonImmutable $dob
 * @property Gender $gender
 * @property string $phone
 * @property string|null $photo_path
 * @property CarbonImmutable|null $nida_verified_at
 * @property CarbonImmutable|null $otp_verified_at
 * @property CarbonImmutable|null $face_verified_at
 * @property MaritalStatus|null $marital_status
 * @property int|null $region_id
 * @property int|null $district_id
 * @property int|null $ward_id
 * @property int|null $street_id
 * @property ResidenceType|null $residence_type
 * @property int|null $customer_category_id
 * @property array<string, mixed>|null $dynamic_form_data
 * @property int $branch_id
 * @property KycStatus $kyc_status
 * @property CustomerStatus $status
 * @property CustomerApprovalStatus $approval_status
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $rejection_reason
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
class Customer extends Model
{
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_number', 'nida_number',
        'first_name', 'middle_name', 'last_name', 'dob', 'gender', 'phone', 'photo_path',
        'nida_verified_at', 'otp_verified_at', 'face_verified_at',
        'marital_status', 'region_id', 'district_id', 'ward_id', 'street_id', 'residence_type',
        'customer_category_id', 'dynamic_form_data', 'branch_id',
        'kyc_status', 'status',
        'approval_status', 'approved_by', 'approved_at', 'rejection_reason',
        'created_by',
    ];

    /**
     * Mirrors the frontend's customerFullName().
     */
    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->middle_name, $this->last_name]));
    }

    /**
     * @return BelongsTo<CustomerCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'customer_category_id');
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return BelongsTo<Region, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return BelongsTo<Ward, $this>
     */
    public function ward(): BelongsTo
    {
        return $this->belongsTo(Ward::class);
    }

    /**
     * @return BelongsTo<Street, $this>
     */
    public function street(): BelongsTo
    {
        return $this->belongsTo(Street::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return HasOne<CustomerBankDetail, $this>
     */
    public function bankDetails(): HasOne
    {
        return $this->hasOne(CustomerBankDetail::class);
    }

    /**
     * @return HasMany<CustomerDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    /**
     * @return HasMany<Guarantor, $this>
     */
    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    /**
     * @return HasMany<NextOfKin, $this>
     */
    public function nextOfKin(): HasMany
    {
        return $this->hasMany(NextOfKin::class);
    }

    /**
     * @return HasMany<CustomerNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /**
     * @return HasMany<GroupMember, $this>
     */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /**
     * Every loan this customer has ever held.
     *
     * Deliberately unfiltered: the §6 eligibility gate needs both the open
     * ones (the "one loan at a time" rule) and the closed ones (their
     * `frozen_until` drives the post-closure cooldown).
     *
     * @return HasMany<Loan, $this>
     */
    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    /**
     * Freeze history, newest first. Polymorphic by convention rather than
     * Laravel's morph map, because `freezable_type` holds the spec's short
     * domain word ('customer'), not a class name.
     *
     * @return HasMany<AccountFreeze, $this>
     */
    public function freezes(): HasMany
    {
        return $this->hasMany(AccountFreeze::class, 'freezable_id')
            ->where('freezable_type', FreezableType::Customer)
            ->latest('frozen_at');
    }

    /**
     * The freeze that is currently in force, if any.
     */
    public function openFreeze(): ?AccountFreeze
    {
        return AccountFreeze::query()
            ->where('freezable_type', FreezableType::Customer)
            ->where('freezable_id', $this->getKey())
            ->whereNull('unfrozen_at')
            ->latest('frozen_at')
            ->first();
    }

    public function isFrozen(): bool
    {
        return $this->status === CustomerStatus::Frozen;
    }

    /**
     * Whether this customer may be attached to a loan application (§9).
     *
     * KYC complete, not frozen or suspended, and — where the category demands
     * extra approval — actually approved. Phase 5's loan engine calls this
     * rather than re-deriving the rule.
     */
    public function isLoanEligible(): bool
    {
        return $this->kyc_status->isComplete()
            && ! $this->status->blocksNewLoans()
            && $this->approval_status !== CustomerApprovalStatus::Pending
            && $this->approval_status !== CustomerApprovalStatus::Rejected;
    }

    /**
     * @param Builder<Customer> $query
     * @return Builder<Customer>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $like = '%'.$term.'%';

        return $query->where(function (Builder $q) use ($like): void {
            $q->where('customer_number', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('nida_number', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                // The frontend searches on the assembled full name, so a query
                // spanning two name columns ("Amina Juma") has to match too.
                ->orWhereRaw(
                    "CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?",
                    [$like],
                );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'gender' => Gender::class,
            'marital_status' => MaritalStatus::class,
            'residence_type' => ResidenceType::class,
            'kyc_status' => KycStatus::class,
            'status' => CustomerStatus::class,
            'approval_status' => CustomerApprovalStatus::class,
            'dynamic_form_data' => 'array',
            'nida_verified_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'face_verified_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
