<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Loans\Enums\LoanStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One tier of the approval chain, as administrator-managed data.
 *
 * Seeded as Branch Manager → Zone Manager → Head Office Credit. The chain is
 * read from these rows on every decision, so deactivating the zone tier or
 * reordering two stages is a configuration change rather than a deploy.
 *
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string|null $description
 * @property int $sequence
 * @property LoanStatus $loan_status
 * @property string $required_permission
 * @property bool $requires_mandate_before
 * @property bool $is_active
 */
class LoanApprovalStage extends Model
{
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name', 'code', 'description', 'sequence',
        'loan_status', 'required_permission', 'requires_mandate_before',
        'requires_branch_zone', 'issues_payment_reference', 'is_active',
    ];

    /** @return HasMany<LoanApprovalDecision, $this> */
    public function decisions(): HasMany
    {
        return $this->hasMany(LoanApprovalDecision::class);
    }

    /**
     * The live chain, in order.
     *
     * Inactive stages are excluded here rather than filtered by each caller —
     * a stage that is switched off must be invisible to the workflow, not
     * something every query has to remember to exclude.
     *
     * @return Collection<int, self>
     */
    public static function chain(): Collection
    {
        return self::query()->where('is_active', true)->orderBy('sequence')->get();
    }

    /** The stage a loan in this status is waiting on, if any. */
    public static function forStatus(LoanStatus $status): ?self
    {
        return self::query()
            ->where('is_active', true)
            ->where('loan_status', $status->value)
            ->first();
    }

    /**
     * The permission an approver needs here, as an enum case.
     *
     * Null when the column names a permission that no longer exists — a
     * configuration error, and the workflow refuses the decision rather than
     * letting an unrecognised string read as "no permission required".
     */
    public function permission(): ?PermissionName
    {
        return PermissionName::tryFrom($this->required_permission);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'loan_status' => LoanStatus::class,
            'sequence' => 'integer',
            'requires_mandate_before' => 'boolean',
            'requires_branch_zone' => 'boolean',
            'issues_payment_reference' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
