<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CategorySector;
use App\Domain\Customers\Enums\RiskTier;
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
                /* Derived from the name unless one was supplied. The code is an
                   internal key, and an administrator who names a customer type
                   should never be asked to invent an identifier for it too. */
                'code' => $data['code'] ?? $this->deriveCode($data['name']),
                'description' => $data['description'] ?? null,
                'form_title' => $data['formTitle'] ?? null,
                /* A new type is offered unless the administrator says otherwise. */
                'is_active' => $data['isActive'] ?? true,
                'sort_order' => $data['sortOrder'] ?? 0,
                /*
                 * Neutral defaults, not business decisions. The form asks for a
                 * name only, so a new type starts demanding nothing of a
                 * customer; what it eventually asks for is configuration that
                 * this pass deliberately does not collect.
                 */
                'risk_tier' => $data['riskTier'] ?? RiskTier::Medium->value,
                'sector' => $data['sector'] ?? CategorySector::Other->value,
                'required_documents' => $data['requiredDocuments'] ?? [],
                'optional_documents' => $data['optionalDocuments'] ?? [],
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
                /*
                 * EVERY OTHER FIELD IS KEYED ON PRESENCE.
                 *
                 * The edit form sends a name and nothing else. `?? []` would
                 * read a missing key as "empty" and wipe the required
                 * documents, the dynamic fields and the risk tier off a type
                 * the administrator only meant to rename — and `?? true` would
                 * quietly put a retired type back into the registration picker.
                 */
                ...array_key_exists('code', $data) ? ['code' => $data['code']] : [],
                ...array_key_exists('description', $data) ? ['description' => $data['description']] : [],
                ...array_key_exists('formTitle', $data) ? ['form_title' => $data['formTitle']] : [],
                ...array_key_exists('isActive', $data) ? ['is_active' => $data['isActive']] : [],
                ...array_key_exists('sortOrder', $data) ? ['sort_order' => $data['sortOrder']] : [],
                ...array_key_exists('riskTier', $data) ? ['risk_tier' => $data['riskTier']] : [],
                ...array_key_exists('sector', $data) ? ['sector' => $data['sector']] : [],
                ...array_key_exists('requiredDocuments', $data) ? ['required_documents' => $data['requiredDocuments']] : [],
                ...array_key_exists('optionalDocuments', $data) ? ['optional_documents' => $data['optionalDocuments'] ?? []] : [],
                ...array_key_exists('dynamicFormSchema', $data) ? ['dynamic_form_schema' => $data['dynamicFormSchema']] : [],
                ...array_key_exists('requiresExtraApproval', $data) ? ['requires_extra_approval' => $data['requiresExtraApproval']] : [],
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
     * An internal key from the administrator's own words.
     *
     *     Wajasiriamali        -> WAJASIRIAMALI
     *     Watumishi wa Umma    -> WATUMISHI_WA_UMMA
     *
     * Uppercased, non-alphanumerics collapsed to underscores, trimmed to the
     * column's 40 characters. A collision — two names that reduce to the same
     * key, or a name matching a soft-deleted type — takes a numeric suffix
     * rather than failing in front of somebody who never asked for a code in
     * the first place. Soft-deleted rows are counted, because the unique index
     * still covers them.
     *
     * Falls back to `TYPE` when a name reduces to nothing at all, which a name
     * written entirely in a non-Latin script would.
     */
    private function deriveCode(string $name): string
    {
        $base = trim(preg_replace('/_+/', '_', preg_replace('/[^A-Z0-9]+/', '_', strtoupper($name))) ?? '', '_');

        if ($base === '') {
            $base = 'TYPE';
        }

        $base = substr($base, 0, 40);
        $candidate = $base;
        $suffix = 2;

        while (CustomerCategory::withTrashed()->where('code', $candidate)->exists()) {
            $tail = '_'.$suffix;
            $candidate = substr($base, 0, 40 - strlen($tail)).$tail;
            $suffix++;
        }

        return $candidate;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(CustomerCategory $category): array
    {
        return [
            'name' => $category->name,
            'code' => $category->code,
            'is_active' => $category->is_active,
            'risk_tier' => $category->risk_tier->value,
            'sector' => $category->sector->value,
            'form_title' => $category->form_title,
            'required_documents' => $category->required_documents,
            'optional_documents' => $category->optional_documents,
            /* The configured form itself is audited: changing which questions a
               customer type asks is a policy change, and "who added the salary
               field, and when" is exactly the question an audit gets asked. */
            'dynamic_form_schema' => $category->dynamic_form_schema,
            'requires_extra_approval' => $category->requires_extra_approval,
        ];
    }
}
