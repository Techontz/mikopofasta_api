<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * KYC verification state of a customer (NIDA, OTP, face liveness, category answers).
 */
class CustomerKyc extends Model
{
    protected $table = 'customer_kyc';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nida_data' => 'array',
            'category_answers' => 'array',
            'nida_verified_at' => 'datetime',
            'otp_verified_at' => 'datetime',
            'face_verified_at' => 'datetime',
            'category_assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'face_liveness_score' => 'float',
            'face_match_score' => 'float',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function categoryAssigner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'category_assigned_by');
    }
}
