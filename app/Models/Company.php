<?php

namespace App\Models;

use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'penalty_value' => 'decimal:2',
            'reserve_percent' => 'decimal:2',
            'dividend_shareholder_percent' => 'decimal:2',
            'dividend_reinvest_percent' => 'decimal:2',
            'expense_approval_limit' => 'decimal:2',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function customerCategories(): HasMany
    {
        return $this->hasMany(CustomerCategory::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function loanCategories(): HasMany
    {
        return $this->hasMany(LoanCategory::class);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
}
