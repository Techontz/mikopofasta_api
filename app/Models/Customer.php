<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Enums\FaceScanStatus;
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

        // KYC detail — real columns rather than dynamic_form_data, because the
        // business searches and reports on these. See the 2026_08_02 migration.
        'alternative_phone', 'email', 'nationality', 'national_id_number',
        'tin_number', 'passport_number',
        'village', 'house_number', 'postal_code', 'landmark',
        'occupation', 'employer', 'monthly_income', 'employment_type',
        'business_name', 'business_type', 'business_address',
        'bank_name', 'bank_branch', 'account_name', 'account_number',
        'mobile_money_provider', 'wallet_number',
        'profile_photo', 'face_photo',
        'registration_source', 'created_device', 'updated_device',

        // Legacy registration form — see the 2026_08_02 migrations.
        'employee_id', 'loan_type_id', 'customer_type_id', 'account_type_id', 'work_type_id',
        'employment_type_id', 'occupation_id', 'marital_status_id', 'bank_id',
        'mobile_money_provider_id',
        'nickname', 'department', 'council_number', 'place_of_employment', 'retirement_date',
        'dependents_count', 'basic_salary', 'take_home', 'check_number',
        'voter_id_number', 'driver_licence_number', 'work_id_number',
        'card_last_four', 'card_expiry_month', 'card_expiry_year',
        // The active face scan's summary — see the 2026_08_14 migration for why
        // it is denormalised here as well as held on `face_scans`.
        'active_face_scan_id', 'face_scan_status', 'face_scan_quality',
        'face_scan_version', 'face_scanned_at', 'face_scanned_by',

        'kyc_status', 'status',
        // Why the account stands as it does — see the 2026_08_16 migration.
        'status_reason', 'status_remarks', 'status_changed_at', 'status_changed_by',
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
     * Every face scan this customer has ever been through, newest first.
     *
     * Deliberately unfiltered and never pruned: a re-scan supersedes its
     * predecessor rather than erasing it, which is the whole reason the
     * history table exists.
     *
     * @return HasMany<FaceScan, $this>
     */
    public function faceScans(): HasMany
    {
        /* Id breaks the tie for the same reason the freeze history does:
           `scanned_at` has second resolution, and a re-scan taken moments
           after the one it supersedes must still sort after it. */
        return $this->hasMany(FaceScan::class)->latest('scanned_at')->latest('id');
    }

    /**
     * @return BelongsTo<FaceScan, $this>
     */
    public function activeFaceScan(): BelongsTo
    {
        return $this->belongsTo(FaceScan::class, 'active_face_scan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function faceScanOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'face_scanned_by');
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
            /* Id breaks the tie: `frozen_at` has second resolution, and two
               freezes within the same second would otherwise come back in
               whatever order the engine felt like — which makes "the current
               one" ambiguous on exactly the rows a dispute is about. */
            ->latest('frozen_at')
            ->latest('id');
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

        /*
         * Everything a teller might have in their hand.
         *
         * This used to cover six columns — number, phone, NIDA and the three
         * name parts. In a branch the customer arrives with whatever they
         * have: a bank slip carrying an account number, an M-Pesa message with
         * a wallet number, a TIN certificate, a loan agreement. Searching only
         * what we happened to store first meant the officer had to ask them for
         * a different piece of paper.
         *
         * Every scalar column below is indexed (see the 2026_08_02 migration),
         * so the added breadth costs one index seek each rather than a scan.
         * The three relationship clauses are `whereHas` sub-queries and are
         * deliberately last: they are the expensive ones, and SQL only reaches
         * them for rows the cheap OR-group has not already matched.
         */
        return $query->where(function (Builder $q) use ($like): void {
            $q->where('customer_number', 'like', $like)
                ->orWhere('phone', 'like', $like)
                ->orWhere('alternative_phone', 'like', $like)
                ->orWhere('nida_number', 'like', $like)
                ->orWhere('national_id_number', 'like', $like)
                ->orWhere('passport_number', 'like', $like)
                ->orWhere('tin_number', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('account_number', 'like', $like)
                ->orWhere('wallet_number', 'like', $like)
                ->orWhere('business_name', 'like', $like)
                ->orWhere('occupation', 'like', $like)
                ->orWhere('first_name', 'like', $like)
                ->orWhere('middle_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                // The frontend searches on the assembled full name, so a query
                // spanning two name columns ("Amina Juma") has to match too.
                ->orWhereRaw(
                    "CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?",
                    [$like],
                )
                // Branch by name, because that is what the officer knows it as.
                ->orWhereHas('branch', fn (Builder $b) => $b->where('name', 'like', $like))
                // A loan number identifies the customer holding it.
                ->orWhereHas('loans', fn (Builder $l) => $l->where('loan_number', 'like', $like))
                // And a guarantor's name finds who they stood for.
                ->orWhereHas('guarantors', fn (Builder $g) => $g->where('name', 'like', $like));
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
            // Minor units, like every other amount in this system.
            'monthly_income' => 'integer',
            'basic_salary' => 'integer',
            'take_home' => 'integer',
            'dependents_count' => 'integer',
            'retirement_date' => 'date',
            'nida_verified_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'face_verified_at' => 'datetime',
            'face_scan_status' => FaceScanStatus::class,
            'face_scan_quality' => 'integer',
            'face_scanned_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }
}
