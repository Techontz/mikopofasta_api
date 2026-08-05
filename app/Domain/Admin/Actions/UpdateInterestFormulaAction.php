<?php

declare(strict_types=1);

namespace App\Domain\Admin\Actions;

use App\Domain\Admin\Exceptions\SystemConfigurationException;
use App\Enums\AuditAction;
use App\Models\InterestFormula;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Renames an interest formula, or rewrites its description.
 *
 * ## Why there is no create and no delete
 *
 * `code` is not editable, and formulas cannot be added or removed, because the
 * code is not a label — it is the key InterestStrategyRegistry resolves a
 * strategy by, so every code must have a class in
 * app/Domain/Loans/Engine/Strategies implementing it. The frontend agrees and
 * offers name and description only (features/admin/interest-formulas/actions.ts).
 *
 * A fourth row would be a formula nothing knows how to compute — a product
 * could be configured with it and every loan priced from that product would
 * fail at origination. Deleting one would orphan every product using it.
 *
 * So this screen edits what a formula is *called* and how it is *explained*,
 * which is genuinely useful — "REDUCING" tells a new officer nothing — and
 * changes no arithmetic at all. Adding a formula is a code change, and should
 * be.
 */
final class UpdateInterestFormulaAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(InterestFormula $formula, string $name, ?string $description, User $actor): InterestFormula
    {
        $this->guardName($formula, $name);

        return DB::transaction(function () use ($formula, $name, $description, $actor): InterestFormula {
            $before = $formula->only(['name', 'description']);

            $formula->update(['name' => $name, 'description' => $description]);

            $this->audit->log(
                AuditAction::InterestFormulaUpdated,
                $formula,
                before: $before,
                after: $formula->only(['name', 'description']),
                actor: $actor,
            );

            return $formula->fresh();
        });
    }

    private function guardName(InterestFormula $formula, string $name): void
    {
        $taken = InterestFormula::query()
            ->whereKeyNot($formula->getKey())
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();

        if ($taken) {
            throw SystemConfigurationException::duplicateName($name);
        }
    }
}
