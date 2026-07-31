<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Services\DynamicFormValidator;
use App\Domain\Customers\Services\KycEvaluator;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * PUT /customers/{customer}/category — spec §15.1.
 *
 * Assigns a category and validates the dynamic KYC data against that
 * category's schema.
 */
final class AssignCustomerCategoryAction
{
    public function __construct(
        private readonly DynamicFormValidator $dynamicForm,
        private readonly KycEvaluator $kyc,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $dynamicFormData
     */
    public function handle(
        Customer $customer,
        CustomerCategory $category,
        array $dynamicFormData,
        User $actor,
    ): Customer {
        $clean = $this->dynamicForm->validate($category, $dynamicFormData);

        return DB::transaction(function () use ($customer, $category, $clean, $actor): Customer {
            $before = [
                'customer_category_id' => $customer->customer_category_id,
                'approval_status' => $customer->approval_status->value,
            ];

            $customer->update([
                'customer_category_id' => $category->getKey(),
                'dynamic_form_data' => $clean,
            ]);

            /*
             * Moving between categories can change whether extra approval is
             * needed. A customer moved INTO a category that requires it must
             * go back to pending — otherwise reassignment becomes a way to
             * skip the approval the category exists to demand.
             *
             * A customer already approved or rejected keeps that decision:
             * re-deciding is the approver's call, not a side effect of an
             * edit.
             */
            if ($customer->approval_status === CustomerApprovalStatus::NotRequired && $category->needsApproval()) {
                $customer->update(['approval_status' => CustomerApprovalStatus::Pending]);
            }

            $customer->load('bankDetails');
            $this->kyc->refresh($customer);

            $this->audit->log(
                AuditAction::CustomerCategoryAssigned,
                $customer,
                before: $before,
                after: [
                    'customer_category_id' => $category->getKey(),
                    'approval_status' => $customer->fresh()->approval_status->value,
                    'kyc_status' => $customer->kyc_status->value,
                ],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch', 'bankDetails']);
        });
    }
}
