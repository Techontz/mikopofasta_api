<?php

declare(strict_types=1);

namespace App\Domain\Customers\Actions;

use App\Domain\Customers\DTOs\BankDetailsData;
use App\Domain\Customers\DTOs\GuarantorData;
use App\Domain\Customers\DTOs\NextOfKinData;
use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\CustomerStatus;
use App\Domain\Customers\Exceptions\CustomerAlreadyRegisteredException;
use App\Domain\Customers\Services\CustomerNumberGenerator;
use App\Domain\Customers\Services\DynamicFormValidator;
use App\Domain\Customers\Services\KycEvaluator;
use App\Enums\AuditAction;
use App\Models\Customer;
use App\Models\CustomerCategory;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Registers a customer from the completed wizard payload (POST /customers).
 *
 * This is §15.1's `POST /customers` carrying the frontend's full
 * RegisterCustomerInput: identity, address, category, dynamic KYC data, bank
 * details, guarantors and next-of-kin all arrive together, because that is how
 * the wizard submits.
 *
 * One transaction, deliberately. A customer whose guarantors failed to save,
 * or whose bank details are missing, is not a partially-registered customer —
 * it is a customer whose KYC checklist silently lies about being complete.
 */
final class RegisterCustomerAction
{
    public function __construct(
        private readonly CustomerNumberGenerator $numbers,
        private readonly DynamicFormValidator $dynamicForm,
        private readonly KycEvaluator $kyc,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array<string, mixed> $payload already validated by RegisterCustomerRequest
     */
    public function handle(array $payload, User $actor): Customer
    {
        $category = CustomerCategory::query()->findOrFail($payload['customerCategoryId']);

        /*
         * Checked before the transaction opens as well as by the UNIQUE index.
         * The index alone would surface as a 500; this gives the wizard a
         * field error it can render on the NIDA input.
         */
        if (Customer::query()->where('nida_number', $payload['nidaNumber'])->exists()) {
            throw new CustomerAlreadyRegisteredException;
        }

        $dynamicData = $this->dynamicForm->validate($category, (array) ($payload['dynamicFormData'] ?? []));

        return DB::transaction(function () use ($payload, $category, $dynamicData, $actor): Customer {
            $customer = Customer::query()->create([
                'customer_number' => $this->numbers->next(),
                'nida_number' => $payload['nidaNumber'],

                // Identity comes from NIDA, never typed (§9).
                'first_name' => $payload['firstName'],
                'middle_name' => $payload['middleName'] ?? null,
                'last_name' => $payload['lastName'],
                'dob' => $payload['dob'],
                'gender' => $payload['gender'],
                'phone' => $payload['phone'],

                'nida_verified_at' => $payload['nidaVerifiedAt'],
                'otp_verified_at' => $payload['otpVerifiedAt'],
                'face_verified_at' => $payload['faceVerifiedAt'],

                'marital_status' => $payload['maritalStatus'] ?? null,
                'region_id' => $payload['regionId'] ?? null,
                'district_id' => $payload['districtId'] ?? null,
                'ward_id' => $payload['wardId'] ?? null,
                'street_id' => $payload['streetId'] ?? null,
                'residence_type' => $payload['residenceType'] ?? null,

                'customer_category_id' => $category->getKey(),
                'dynamic_form_data' => $dynamicData,
                'branch_id' => $payload['branchId'],

                'status' => CustomerStatus::Active,

                /*
                 * §2.3's requires_extra_approval decides this. A category that
                 * demands it registers `pending` and is not loan-eligible
                 * until someone with customers.approve decides.
                 */
                'approval_status' => $category->needsApproval()
                    ? CustomerApprovalStatus::Pending
                    : CustomerApprovalStatus::NotRequired,

                'created_by' => $actor->getKey(),
            ]);

            if (isset($payload['bankDetails']) && is_array($payload['bankDetails'])) {
                $bank = BankDetailsData::fromArray($payload['bankDetails']);

                $customer->bankDetails()->create([
                    'bank_name' => $bank->bankName,
                    'account_number' => $bank->accountNumber,
                    'account_name' => $bank->accountName,
                    'phone_number' => $bank->phoneNumber,
                    'check_number' => $bank->checkNumber,
                ]);
            }

            foreach ((array) ($payload['guarantors'] ?? []) as $row) {
                $guarantor = GuarantorData::fromArray($row);

                $customer->guarantors()->create([
                    'name' => $guarantor->name,
                    'phone' => $guarantor->phone,
                    'nida_number' => $guarantor->nidaNumber,
                    'relationship' => $guarantor->relationship,
                    'address' => $guarantor->address,
                    'occupation' => $guarantor->occupation,
                ]);
            }

            foreach ((array) ($payload['nextOfKin'] ?? []) as $row) {
                $kin = NextOfKinData::fromArray($row);

                $customer->nextOfKin()->create([
                    'name' => $kin->name,
                    'relationship' => $kin->relationship,
                    'phone' => $kin->phone,
                    'address' => $kin->address,
                ]);
            }

            /*
             * Derived, never asserted. The wizard collects everything the
             * checklist needs, so this normally lands on `completed` — but a
             * payload without bank details legitimately does not, and saying
             * so is the whole point of the checklist.
             */
            $customer->load('bankDetails');
            $this->kyc->refresh($customer);

            $this->audit->log(
                AuditAction::CustomerRegistered,
                $customer,
                after: [
                    'customer_number' => $customer->customer_number,
                    'branch_id' => $customer->branch_id,
                    'customer_category_id' => $customer->customer_category_id,
                    'kyc_status' => $customer->kyc_status->value,
                    'approval_status' => $customer->approval_status->value,
                ],
                actor: $actor,
            );

            return $customer->fresh(['category', 'branch', 'bankDetails', 'guarantors', 'nextOfKin']);
        });
    }
}
