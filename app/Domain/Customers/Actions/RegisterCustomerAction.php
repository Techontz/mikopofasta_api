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
use App\Domain\Customers\Support\MaritalStatusMirror;
use App\Enums\AuditAction;
use App\Domain\Customers\Enums\PaymentMethod;
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
        /* Optional — see RegisterCustomerRequest. No category means no
           category-specific questions to validate. */
        $category = isset($payload['customerCategoryId'])
            ? CustomerCategory::query()->findOrFail($payload['customerCategoryId'])
            : null;

        /*
         * Checked before the transaction opens as well as by the UNIQUE index.
         * The index alone would surface as a 500; this gives the wizard a
         * field error it can render on the NIDA input.
         */
        $nidaNumber = $payload['nidaNumber'] ?? null;

        if ($nidaNumber !== null
            && Customer::query()->where('nida_number', $nidaNumber)->exists()) {
            throw new CustomerAlreadyRegisteredException;
        }

        $dynamicData = $category === null
            ? []
            /* The whole payload, not only the JSON half. A configured field
               may name a real customer column — see StructuredRegistrationField
               — and its `required` rule has to be judged against the value that
               actually arrived, which for those fields is a top-level key. */
            : $this->dynamicForm->validate($category, (array) ($payload['dynamicFormData'] ?? []), $payload);

        return DB::transaction(function () use ($payload, $category, $dynamicData, $actor, $nidaNumber): Customer {
            $customer = Customer::query()->create([
                'customer_number' => $this->numbers->next(),
                'nida_number' => $nidaNumber,

                /*
                 * Identity is typed by the officer while manual registration is
                 * the only option. §9 says NIDA is the source of truth and these
                 * are never hand-entered — which is right, and is what a
                 * `NidaIdentityProvider` will restore. It cannot be true today:
                 * there is no registry to read, and the simulator that pretended
                 * to be one invented the name it filled in.
                 */
                'first_name' => $payload['firstName'],
                'middle_name' => $payload['middleName'] ?? null,
                'last_name' => $payload['lastName'],
                'dob' => $payload['dob'],
                'gender' => $payload['gender'],
                'phone' => $payload['phone'],

                /*
                 * Null on a manual registration, and null is the honest value:
                 * no check ran. The KYC evaluator reads these, so a
                 * hand-entered customer is correctly rated as unverified rather
                 * than inheriting a verified status it never earned.
                 */
                'nida_verified_at' => $payload['nidaVerifiedAt'] ?? null,
                'otp_verified_at' => $payload['otpVerifiedAt'] ?? null,
                /*
                 * ALWAYS NULL AT CREATION. A customer is created awaiting face
                 * verification; the column is stamped only by
                 * VerifyCustomerFaceAction, and only when a liveness sequence
                 * actually passes. It used to be copied from the payload, which
                 * let a caller register a customer as already verified and skip
                 * the scan — the request now refuses such a claim as well, so
                 * this is the second of two locks on the same door.
                 */
                'face_verified_at' => null,

                /* Derived from the chosen list entry when the caller did not
                   name the enum itself — see MaritalStatusMirror. The form asks
                   through the admin-managed list and writes the id below; this
                   is what stops the profile reading a dash for an answer the
                   officer gave. */
                'marital_status' => MaritalStatusMirror::resolve($payload),
                'region_id' => $payload['regionId'] ?? null,
                'district_id' => $payload['districtId'] ?? null,
                'ward_id' => $payload['wardId'] ?? null,
                'street_id' => $payload['streetId'] ?? null,
                /* Typed, not chosen — see the 2026_08_26 migration. The ids
                   above stay for records that already reference a row. */
                'ward_name' => $payload['wardName'] ?? null,
                'street_name' => $payload['streetName'] ?? null,
                'residence_type' => $payload['residenceType'] ?? null,

                /*
                 * The KYC detail block. Written as real columns so a teller can
                 * search a TIN or an account number, and so reporting can group
                 * by occupation or employment type — neither of which is
                 * possible against `dynamic_form_data`, which stays for the
                 * per-category questions it was built for.
                 */
                'alternative_phone' => $payload['alternativePhone'] ?? null,
                'email' => $payload['email'] ?? null,
                'nationality' => $payload['nationality'] ?? null,
                'national_id_number' => $payload['nationalIdNumber'] ?? null,
                'tin_number' => $payload['tinNumber'] ?? null,
                'passport_number' => $payload['passportNumber'] ?? null,

                'village' => $payload['village'] ?? null,
                'house_number' => $payload['houseNumber'] ?? null,
                'postal_code' => $payload['postalCode'] ?? null,
                'landmark' => $payload['landmark'] ?? null,

                'occupation' => $payload['occupation'] ?? null,
                'employer' => $payload['employer'] ?? null,
                'monthly_income' => $payload['monthlyIncome'] ?? null,
                /* Both free text. No list of occupations is complete, and one
                   that is not complete forces a wrong answer. */
                'employment_type' => $payload['employmentType'] ?? null,
                'work_type' => $payload['workType'] ?? null,

                'business_name' => $payload['businessName'] ?? null,
                'business_type' => $payload['businessType'] ?? null,
                'business_address' => $payload['businessAddress'] ?? null,

                /*
                 * Mirrored from the bank block the wizard already sends, so a
                 * search by account number is one indexed lookup instead of a
                 * join. `customer_bank_details` remains the record of account.
                 */
                'bank_name' => $payload['bankDetails']['bankName'] ?? null,
                'bank_branch' => $payload['bankBranch'] ?? null,
                'account_name' => $payload['accountName'] ?? $payload['bankDetails']['accountName'] ?? null,
                'account_number' => $payload['bankDetails']['accountNumber'] ?? null,
                'mobile_money_provider' => $payload['mobileMoneyProvider'] ?? null,
                'wallet_number' => $payload['walletNumber'] ?? null,
                'payment_method' => self::paymentMethod($payload)?->value,

                /* Server-decided, not client-supplied: a record must not be
                   able to claim it came from NIDA when it was typed by hand. */
                'registration_source' => ($payload['nidaVerifiedAt'] ?? null) !== null ? 'nida' : 'manual',
                'created_device' => $payload['createdDevice'] ?? null,

                // ---- legacy step 1 ----
                /*
                 * Defaults to whoever is registering.
                 *
                 * It was a dropdown of every member of staff, defaulting to
                 * nobody — so the field that decides whose book the customer
                 * sits on was routinely left blank, and the officer sitting
                 * with the customer had to find their own name in a list of
                 * everyone. The signed-in user is the answer in every ordinary
                 * case; a supervisor may name somebody else, which needs
                 * `customers.assign_officer` (see RegisterCustomerRequest).
                 */
                'employee_id' => $payload['employeeId'] ?? $actor->getKey(),
                'loan_type_id' => $payload['loanTypeId'] ?? null,
                'customer_type_id' => $payload['customerTypeId'] ?? null,

                // ---- legacy step 2 ----
                'nickname' => $payload['nickname'] ?? null,
                'account_type_id' => $payload['accountTypeId'] ?? null,
                'work_type_id' => $payload['workTypeId'] ?? null,
                'employment_type_id' => $payload['employmentTypeId'] ?? null,
                'occupation_id' => $payload['occupationId'] ?? null,
                'marital_status_id' => $payload['maritalStatusId'] ?? null,
                'department' => $payload['department'] ?? null,
                'council_number' => $payload['councilNumber'] ?? null,
                'place_of_employment' => $payload['placeOfEmployment'] ?? null,
                'retirement_date' => $payload['retirementDate'] ?? null,
                'dependents_count' => $payload['dependentsCount'] ?? null,
                'basic_salary' => $payload['basicSalary'] ?? null,
                'take_home' => $payload['takeHome'] ?? null,
                /* Where they serve and on what terms — 2026_08_30. */
                'sector_id' => $payload['sectorId'] ?? null,
                'sector_category_id' => $payload['sectorCategoryId'] ?? null,
                'contract_type_id' => $payload['contractTypeId'] ?? null,
                'contract_expiry_date' => $payload['contractExpiryDate'] ?? null,
                'employer_id' => $payload['employerId'] ?? null,
                'check_number' => $payload['checkNumber'] ?? null,

                /* Identity as one type plus one number. The six named columns
                   below still receive whatever the request carried, so a
                   client written before this pair existed loses nothing. */
                'id_type_id' => $payload['idTypeId'] ?? null,
                'id_number' => $payload['idNumber'] ?? null,

                // ---- legacy step 3 ----
                'voter_id_number' => $payload['voterIdNumber'] ?? null,
                'driver_licence_number' => $payload['driverLicenceNumber'] ?? null,
                'work_id_number' => $payload['workIdNumber'] ?? null,
                'bank_id' => $payload['bankId'] ?? null,
                'mobile_money_provider_id' => $payload['mobileMoneyProviderId'] ?? null,
                /*
                 * Only the last four digits survive. The full number arrived in
                 * the request and dies with it — nothing writes a PAN, so there
                 * is nothing for a breach to leak and no PCI scope to inherit.
                 */
                'card_last_four' => isset($payload['cardNumber'])
                    ? substr(preg_replace('/\\D/', '', (string) $payload['cardNumber']), -4)
                    : null,
                'card_expiry_month' => $payload['cardExpiryMonth'] ?? null,
                'card_expiry_year' => $payload['cardExpiryYear'] ?? null,

                'customer_category_id' => $category?->getKey(),
                'dynamic_form_data' => $dynamicData,
                'branch_id' => $payload['branchId'],

                'status' => CustomerStatus::Active,

                /*
                 * EVERY registration is approved by a manager. Not only the
                 * categories that ask for extra scrutiny.
                 *
                 * This used to be `$category?->needsApproval() ? Pending :
                 * NotRequired`, and `not_required` passed the loan gate — so a
                 * customer registered into an ordinary category became able to
                 * borrow the moment their face scan passed, with no human ever
                 * having looked at the file. §2.3's `requires_extra_approval`
                 * was never meant to be the only thing standing between a
                 * registration and a loan.
                 *
                 * `requires_extra_approval` still means something: it is read
                 * by the approval screen to mark which files need a closer
                 * look. It no longer decides WHETHER anyone looks.
                 */
                'approval_status' => CustomerApprovalStatus::Pending,

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

    /**
     * Which kind of account the officer chose.
     *
     * Taken from the payload when the client states it, which is the point of
     * storing it at all — the answer is a fact about what was asked, not a
     * summary of which columns happen to be filled.
     *
     * DERIVED ONLY AS A FALLBACK, for a client older than the column. The order
     * matches the frontend's own repair and the backfill in the 2026_09_12
     * migration, so a record reads the same however it was created: a wallet
     * wins, then an account number, then nothing. Keeping the three in step
     * matters more than which way round they are.
     */
    private static function paymentMethod(array $payload): ?PaymentMethod
    {
        $stated = $payload['paymentMethod'] ?? null;

        if (is_string($stated)) {
            return PaymentMethod::tryFrom($stated);
        }

        $filled = static fn (mixed $v): bool => $v !== null && $v !== '';

        if ($filled($payload['walletNumber'] ?? null) || $filled($payload['mobileMoneyProviderId'] ?? null)) {
            return PaymentMethod::Mno;
        }

        if ($filled($payload['bankDetails']['accountNumber'] ?? null) || $filled($payload['bankId'] ?? null)) {
            return PaymentMethod::Bank;
        }

        return null;
    }

}
