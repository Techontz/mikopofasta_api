<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\MasterData\AccountType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one account type requires of a customer — see the 2026_08_26 migration.
 *
 * The row with a null `account_type_id` is the default profile, applied to a
 * customer who has chosen no account type and to any account type nobody has
 * configured. `AccountTypeRequirementResolver` is the only thing that should
 * pick between them; nothing else should be reasoning about which row applies.
 *
 * @property int $id
 * @property int|null $account_type_id
 * @property bool $requires_employment_details
 * @property bool $requires_business_details
 * @property bool $requires_bank_account
 * @property bool $requires_card_details
 * @property int $min_guarantors
 * @property int $min_next_of_kin
 * @property bool $requires_customer_category
 * @property bool $requires_marital_status
 * @property bool $requires_address
 * @property bool $requires_identity_document
 * @property bool $requires_face_verification
 * @property bool $requires_nida_verification
 * @property bool $requires_otp_verification
 * @property string|null $guidance
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class AccountTypeRequirement extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'account_type_id',
        'requires_employment_details', 'requires_business_details',
        'requires_bank_account', 'requires_card_details',
        'min_guarantors', 'min_next_of_kin',
        'requires_customer_category', 'requires_marital_status',
        'requires_address', 'requires_identity_document',
        'requires_face_verification', 'requires_nida_verification', 'requires_otp_verification',
        'guidance', 'updated_by',
    ];

    public function isDefault(): bool
    {
        return $this->account_type_id === null;
    }

    /**
     * @return BelongsTo<AccountType, $this>
     */
    public function accountType(): BelongsTo
    {
        return $this->belongsTo(AccountType::class, 'account_type_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_employment_details' => 'boolean',
            'requires_business_details' => 'boolean',
            'requires_bank_account' => 'boolean',
            'requires_card_details' => 'boolean',
            'min_guarantors' => 'integer',
            'min_next_of_kin' => 'integer',
            'requires_customer_category' => 'boolean',
            'requires_marital_status' => 'boolean',
            'requires_address' => 'boolean',
            'requires_identity_document' => 'boolean',
            'requires_face_verification' => 'boolean',
            'requires_nida_verification' => 'boolean',
            'requires_otp_verification' => 'boolean',
        ];
    }
}
