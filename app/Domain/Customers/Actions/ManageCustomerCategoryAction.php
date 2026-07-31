<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Exceptions\CategoryInUseException;
use App\Enums\AuditAction;
use App\Models\CustomerCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * CRUD for customer categories — the KYC rule engine (§2.3).
 *
 * Editing a category's `dynamic_form_schema` deliberately does NOT re-validate
 * existing customers' stored `dynamic_form_data`. Their data was valid under
 * the schema in force when they registered, and retroactively invalidating it
 * would mark long-standing customers KYC-incomplete because an administrator
 * added a field. Phase 5's loan engine snapshots product terms onto the loan
 * for the same reason.
 */
final class ManageCustomerCategoryAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, User $actor): CustomerCategory
    {
        return DB::transaction(function () use ($data, $actor): CustomerCategory {
            $category = CustomerCategory::query()->create([
                'name' => $data['name'],
                'code' => $data['code'],
                'risk_tier' => $data['riskTier'],
                'sector' => $data['sector'],
                'required_documents' => $data['requiredDocuments'] ?? [],
                'dynamic_form_schema' => $data['dynamicFormSchema'] ?? [],
                'requires_extra_approval' => $data['requiresExtraApproval'] ?? false,
                'created_by' => $actor->getKey(),
            ]);

            $this->audit->log(
                AuditAction::CustomerCategoryCreated,
                $category,
                after: $this->snapshot($category),
                actor: $actor,
            );

            return $category;
        });
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(CustomerCategory $category, array $data, User $actor): CustomerCategory
    {
        return DB::transaction(function () use ($category, $data, $actor): CustomerCategory {
            $before = $this->snapshot($category);

            $category->update([
                'name' => $data['name'],
                'code' => $data['code'],
                'risk_tier' => $data['riskTier'],
                'sector' => $data['sector'],
                'required_documents' => $data['requiredDocuments'] ?? [],
                'dynamic_form_schema' => $data['dynamicFormSchema'] ?? [],
                'requires_extra_approval' => $data['requiresExtraApproval'] ?? false,
            ]);

            $this->audit->log(
                AuditAction::CustomerCategoryUpdated,
                $category,
                before: $before,
                after: $this->snapshot($category->refresh()),
                actor: $actor,
            );

            return $category;
        });
    }

    public function delete(CustomerCategory $category, User $actor): void
    {
        // Mirrors the frontend's deleteCustomerCategory guard. The FK is
        // RESTRICT, so without this the request would be a 500.
        $assigned = $category->customers()->count();

        if ($assigned > 0) {
            throw CategoryInUseException::hasCustomers($assigned);
        }

        DB::transaction(function () use ($category, $actor): void {
            $this->audit->log(
                AuditAction::CustomerCategoryDeleted,
                $category,
                before: $this->snapshot($category),
                actor: $actor,
            );

            $category->delete();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CustomerCategory $category): array
    {
        return [
            'name' => $category->name,
            'code' => $category->code,
            'risk_tier' => $category->risk_tier->value,
            'sector' => $category->sector->value,
            'required_documents' => $category->required_documents,
            'requires_extra_approval' => $category->requires_extra_approval,
        ];
    }
}
