<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Registration requirement profile: the baseline row (no account type, no customer type) plus optional rows
 * per account type and / or customer type.
 */
class AccountTypeRequirement extends Model
{
    /**
     * @var list<string>
     */
    public const FLAGS = [
        'requires_employment_details', 'requires_business_details', 'requires_bank_account', 'requires_card_details',
        'requires_customer_category', 'requires_marital_status', 'requires_address', 'requires_identity_document',
        'requires_category_documents', 'requires_face_verification', 'requires_nida_verification', 'requires_otp_verification',
    ];

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return array_fill_keys(self::FLAGS, 'boolean') + [
            'min_guarantors' => 'integer',
            'min_next_of_kin' => 'integer',
            'category_documents_enforced_from' => 'date',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customerCategory(): BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class);
    }
}
