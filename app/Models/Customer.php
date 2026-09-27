<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\MasterData\Bank;
use App\Models\MasterData\IdType;
use App\Models\MasterData\MaritalStatus;
use App\Models\MasterData\MobileMoneyProvider;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use Auditable, HasFactory, SoftDeletes;

    /**
     * Customer lifecycle statuses (live values: pending / open / out / close).
     *
     * @var array<string, string>
     */
    public const STATUSES = [
        'pending' => 'PENDING',
        'open' => 'ACTIVE',
        'out' => 'DEFAULT',
        'close' => 'CLOSED',
    ];

    /**
     * @var array<string, string>
     */
    public const WORK_STATUSES = [
        'ent' => 'Watumishi',
        'ser' => 'Wajasiliamali',
    ];

    /**
     * @var list<string>
     */
    public const MARITAL_STATUSES = ['Married', 'Single', 'Widow', 'Separated', 'Divorced'];

    /** Registration wizard steps. */
    public const STEP_BASIC = 1;

    public const STEP_ADDITIONAL = 2;

    public const STEP_PASSPORT = 3;

    public const STEP_COMPLETE = 4;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'monthly_income' => 'decimal:2',
            'is_marked' => 'boolean',
            'dynamic_form_data' => 'array',
            'retirement_date' => 'date',
            'contract_expiry_date' => 'date',
            'basic_salary' => 'integer',
            'take_home' => 'integer',
            'dependents' => 'integer',
            'face_scan_quality' => 'integer',
            'face_scanned_at' => 'datetime',
            'face_verified_at' => 'datetime',
            'nida_verified_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Customer $customer): void {
            if ($customer->customer_code === null) {
                $customer->forceFill(['customer_code' => 'C'.$customer->created_at->format('Ym').$customer->id])->saveQuietly();
            }
        });
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->middle_name} {$this->last_name}"));
    }

    /**
     * Middle name shortened to its initial, as on the live profile headers ("FARYJALLAH M JOHN").
     */
    protected function shortName(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.mb_substr((string) $this->middle_name, 0, 1).' '.$this->last_name));
    }

    protected function statusLabel(): Attribute
    {
        return Attribute::get(fn (): string => self::STATUSES[$this->status] ?? strtoupper((string) $this->status));
    }

    protected function photoUrl(): Attribute
    {
        return Attribute::get(fn (): string => $this->passport_photo ? asset('storage/'.$this->passport_photo) : '/assets/img/default.jpeg');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function customerCategory(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function guarantors(): HasMany
    {
        return $this->hasMany(Guarantor::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoanTransaction::class);
    }

    public function savings(): HasMany
    {
        return $this->hasMany(Saving::class);
    }

    public function kyc(): HasOne
    {
        return $this->hasOne(CustomerKyc::class);
    }

    public function nextOfKin(): HasOne
    {
        return $this->hasOne(CustomerNextOfKin::class);
    }

    public function residence(): HasOne
    {
        return $this->hasOne(CustomerResidence::class);
    }

    public function bankDetail(): HasOne
    {
        return $this->hasOne(CustomerBankDetail::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CustomerDocument::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    public function wardRecord(): BelongsTo
    {
        return $this->belongsTo(Ward::class, 'ward_id');
    }

    public function idType(): BelongsTo
    {
        return $this->belongsTo(IdType::class);
    }

    public function maritalStatusRecord(): BelongsTo
    {
        return $this->belongsTo(MaritalStatus::class, 'marital_status_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function mobileMoneyProviderRecord(): BelongsTo
    {
        return $this->belongsTo(MobileMoneyProvider::class, 'mobile_money_provider_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function faceScannedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'face_scanned_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'approved_by');
    }

    /**
     * Next of kin rows (the registration collects a list; `nextOfKin` is the legacy single row).
     */
    public function nextOfKins(): HasMany
    {
        return $this->hasMany(CustomerNextOfKin::class);
    }

    public function faceScans(): HasMany
    {
        return $this->hasMany(FaceScan::class);
    }

    public function activeFaceScan(): BelongsTo
    {
        return $this->belongsTo(FaceScan::class, 'active_face_scan_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    public function salaryAdvances(): HasMany
    {
        return $this->hasMany(SalaryAdvance::class);
    }
}
