<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\DTOs\KycRequirement;
use App\Domain\Customers\Enums\KycStatus;
use App\Models\AccountTypeRequirement;
use App\Models\Customer;

/**
 * The KYC checklist — backend spec §9, now driven by the customer's account
 * type rather than by a fixed list of five.
 *
 * WHAT THIS USED TO DO, AND WHY IT HAD TO CHANGE. The checklist was five
 * hardcoded items, two of which were `nida_verified_at` and `otp_verified_at`.
 * Neither integration exists — there is no NIDA registry to query and no SMS
 * gateway configured — so those two timestamps were null on every customer
 * registered by hand, `isComplete()` returned false for all of them, and
 * `LoanEligibilityChecker` refused every application with KYC_INCOMPLETE. The
 * registration flow could not, even in principle, produce a customer who could
 * borrow. That is the defect at the centre of this work.
 *
 * The fix is not to tick the boxes anyway. It is to separate two things the
 * old code conflated:
 *
 *     WHAT THE INSTITUTION REQUIRES   → `account_type_requirements`, a table
 *     WHAT THIS DEPLOYMENT CAN DO     → config/kyc.php, an environment fact
 *
 * A requirement is only enforced when the institution asks for it AND the
 * check can actually be performed. NIDA and OTP are shipped not-required
 * because they are not available; the columns exist so the day either
 * integration lands the institution turns it on without a release. Until then
 * the checklist reports them honestly — "captured, not externally verified" —
 * and they do not block anybody.
 *
 * Everything else is per account type, which is the client's requirement that
 * "Account Type MUST control the subsequent registration steps". A savings
 * account is not asked for a check number; a salaried loan account is.
 *
 * This remains the single place the rule is expressed. Every write path that
 * could affect it calls `refresh()` rather than setting `kyc_status` directly.
 */
final class KycEvaluator
{
    public function __construct(
        private readonly AccountTypeRequirementResolver $profiles,
        private readonly ExternalVerificationStatus $external,
    ) {}

    /**
     * The full checklist for this customer, in the order an officer works it.
     *
     * @return list<KycRequirement>
     */
    public function requirements(Customer $customer): array
    {
        $profile = $this->profiles->forCustomer($customer);

        return [
            $this->identityDocument($customer, $profile),
            $this->nidaVerification($customer, $profile),
            $this->phoneCaptured($customer),
            $this->otpVerification($customer, $profile),
            $this->address($customer, $profile),
            $this->maritalStatus($customer, $profile),
            $this->employmentDetails($customer, $profile),
            $this->businessDetails($customer, $profile),
            $this->bankAccount($customer, $profile),
            $this->cardDetails($customer, $profile),
            $this->guarantors($customer, $profile),
            $this->nextOfKin($customer, $profile),
            $this->category($customer, $profile),
            $this->categoryDocuments($customer, $profile),
            /*
             * Last, deliberately. Face verification is the final step of the
             * registration workflow and the one that may happen on a different
             * device, hours later — see RegistrationProgress. Ordering it last
             * is what makes "everything done except the face scan" legible at
             * a glance instead of buried mid-list.
             */
            $this->faceVerification($customer, $profile),
        ];
    }

    /**
     * The original five-key map, kept as it was.
     *
     * `requirements()` is what the UI should read. This stays because the
     * kyc-status endpoint has published it since Phase 2 and other callers
     * — the frontend contract test among them — match on these exact keys.
     * Removing it would be an API break for no gain; it is a projection of the
     * same facts, not a second source of truth.
     *
     * @return array{
     *     nidaVerified: bool, otpVerified: bool, faceVerified: bool,
     *     additionalDataComplete: bool, categoryAssigned: bool
     * }
     */
    public function checklist(Customer $customer): array
    {
        return [
            'nidaVerified' => $customer->nida_verified_at !== null,
            'otpVerified' => $customer->otp_verified_at !== null,
            'faceVerified' => $customer->face_verified_at !== null,
            'additionalDataComplete' => $this->hasBankAccount($customer)
                && $customer->marital_status !== null
                && $customer->region_id !== null,
            'categoryAssigned' => $customer->customer_category_id !== null,
        ];
    }

    public function isComplete(Customer $customer): bool
    {
        foreach ($this->requirements($customer) as $requirement) {
            if ($requirement->outstanding()) {
                return false;
            }
        }

        return true;
    }

    /**
     * What is still missing, in words, for the officer and for the API's
     * refusal messages.
     *
     * @return list<string>
     */
    public function outstanding(Customer $customer): array
    {
        $missing = [];

        foreach ($this->requirements($customer) as $requirement) {
            if ($requirement->outstanding()) {
                $missing[] = $requirement->detail ?? $requirement->label;
            }
        }

        return $missing;
    }

    /**
     * Recomputes and persists `kyc_status`.
     *
     * Deliberately two-way: KYC can regress. Removing a customer's bank
     * details, or a failed re-scan clearing `face_verified_at`, genuinely
     * makes them incomplete again, and silently leaving the status at
     * `completed` would let an ineligible customer through the loan gate.
     */
    public function refresh(Customer $customer): Customer
    {
        $status = $this->isComplete($customer) ? KycStatus::Completed : KycStatus::Incomplete;

        if ($customer->kyc_status !== $status) {
            $customer->update(['kyc_status' => $status]);
        }

        return $customer;
    }

    /**
     * Required documents from the customer's category that have not been
     * uploaded yet. Surfaced on the profile ("Missing required documents: …").
     *
     * Also the source for `categoryDocuments()` below, which decides whether
     * the same list BLOCKS. Kept public and separate because the profile panel
     * wants the names whether or not they are blocking.
     *
     * @return list<string>
     */
    public function missingDocuments(Customer $customer): array
    {
        $category = $customer->category;

        if ($category === null) {
            return [];
        }

        $uploaded = $customer->documents->pluck('document_type')->all();

        return array_values(array_diff($category->required_documents, $uploaded));
    }

    /**
     * The documents this customer's CATEGORY asks for.
     *
     * A different question from `identityDocument()` above. That one asks
     * whether the person can be identified at all; this one asks whether the
     * file holds what a public servant, or a boda rider, is required to
     * produce — a confirmation letter, a salary slip, a licence.
     *
     * BLOCKING IS OPTIONAL, DATED, AND OFF. Whether this item blocks is
     * `AccountTypeRequirement::categoryDocumentsApplyTo()`, which reads a flag
     * and a cutoff date against the customer's own registration date. It ships
     * off, so today this reports and does not block — exactly the behaviour
     * that was here before.
     *
     * Why it ships off: `customer_documents` holds no rows, and fifteen of the
     * sixteen loan-eligible customers would have stopped being eligible the
     * moment this became a blocker. The cutoff is what lets it be turned on
     * for new registrations without doing that to the existing book. See the
     * 2026_08_30_000005 and 2026_08_31_000001 migrations.
     *
     * It is a requirement in the list rather than a note on the side so the
     * officer sees the same checklist either way, marked optional until the
     * institution decides otherwise.
     */
    private function categoryDocuments(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $category = $customer->category;
        $missing = $this->missingDocuments($customer);
        /* Same shape as missingDocuments() above: an explicit null branch,
           because a customer may genuinely have no category yet. */
        $required = $category === null ? [] : $category->required_documents;

        return new KycRequirement(
            key: 'categoryDocuments',
            label: 'Category documents on file',
            /* No category, or a category that asks for nothing, is satisfied
               rather than pending — there is nothing outstanding to collect. */
            satisfied: $missing === [],
            required: $required !== [] && $profile->categoryDocumentsApplyTo($customer),
            detail: $missing === []
                ? ($required === []
                    ? 'This category requires no supporting documents.'
                    : 'All '.count($required).' required documents are on file.')
                : 'Missing: '.implode(', ', $missing).'.',
        );
    }

    /* ------------------------------------------------------- the items --- */

    /**
     * At least one identity document, whichever one the customer produced.
     *
     * Six columns rather than one, because a Tanzanian microfinance customer
     * may hold a NIDA card, a voter's card, a driving licence, a passport or a
     * work ID, and demanding a specific one would exclude people who have
     * valid identification of another kind. The KYC question is "can this
     * person be identified from a document on file", not "do they hold a NIDA
     * card".
     */
    private function identityDocument(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $held = $this->identityDocumentsHeld($customer);

        return new KycRequirement(
            key: 'identityDocument',
            label: 'Identity document captured',
            satisfied: $held !== [],
            required: $profile->requires_identity_document,
            detail: $held === []
                ? 'Record at least one of: National ID, voter ID, driving licence, passport or work ID.'
                : 'On file: '.implode(', ', $held).'.',
        );
    }

    /**
     * The registry check — as opposed to the document above.
     *
     * These are two different facts and the checklist shows both. Capturing a
     * National ID number tells us what the customer says their number is;
     * only the registry can tell us it is theirs.
     */
    private function nidaVerification(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $available = $this->external->nidaAvailable();
        $wanted = $profile->requires_nida_verification;

        return new KycRequirement(
            key: 'nidaVerified',
            label: 'NIDA registry verification',
            satisfied: $customer->nida_verified_at !== null,
            /* Only enforced when it can actually be performed. A requirement
               nothing can satisfy is not a control, it is a dead end. */
            required: $wanted && $available,
            blocked: $wanted && ! $available,
            detail: $available
                ? null
                : 'NIDA integration is unavailable. The National ID number is captured and recorded, but not externally verified.',
        );
    }

    private function phoneCaptured(Customer $customer): KycRequirement
    {
        return new KycRequirement(
            key: 'phoneCaptured',
            label: 'Phone number captured',
            satisfied: trim((string) $customer->phone) !== '',
            required: true,
            detail: null,
        );
    }

    private function otpVerification(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $available = $this->external->otpAvailable();
        $wanted = $profile->requires_otp_verification;

        return new KycRequirement(
            key: 'otpVerified',
            label: 'Phone verified by SMS code',
            satisfied: $customer->otp_verified_at !== null,
            required: $wanted && $available,
            blocked: $wanted && ! $available,
            detail: $available
                ? null
                : 'SMS gateway is unavailable. No code has been sent, and none has been verified.',
        );
    }

    /**
     * Region and district from the reference tables; ward and street as typed.
     *
     * Only the two authoritative levels gate. Ward and street are free text
     * precisely because our reference data does not cover the whole country,
     * and requiring a value we cannot check would only encourage a guess.
     */
    private function address(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $missing = [];

        if ($customer->region_id === null) {
            $missing[] = 'region';
        }

        if ($customer->district_id === null) {
            $missing[] = 'district';
        }

        return new KycRequirement(
            key: 'addressCaptured',
            label: 'Address on file',
            satisfied: $missing === [],
            required: $profile->requires_address,
            detail: $missing === [] ? null : 'Select a '.implode(' and a ', $missing).'.',
        );
    }

    private function maritalStatus(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        return new KycRequirement(
            key: 'maritalStatus',
            label: 'Marital status recorded',
            satisfied: $customer->marital_status !== null || $customer->marital_status_id !== null,
            required: $profile->requires_marital_status,
        );
    }

    private function employmentDetails(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $hasEmployer = $this->filled($customer->employer) || $this->filled($customer->place_of_employment);
        $hasKind = $this->filled($customer->work_type) || $this->filled($customer->employment_type);
        /* Any one of the three income figures. Salaried staff give a take-home
           and a basic; a trader gives a monthly income and neither of the
           others. Demanding a specific column would exclude one of them. */
        $hasIncome = $customer->take_home !== null
            || $customer->basic_salary !== null
            || $customer->monthly_income !== null;

        $missing = array_values(array_filter([
            $hasEmployer ? null : 'employer or place of employment',
            $hasKind ? null : 'work type or type of employment',
            $hasIncome ? null : 'an income figure',
        ]));

        return new KycRequirement(
            key: 'employmentDetails',
            label: 'Employment details recorded',
            satisfied: $missing === [],
            required: $profile->requires_employment_details,
            detail: $missing === [] ? null : 'Still needed: '.implode(', ', $missing).'.',
        );
    }

    private function businessDetails(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $satisfied = $this->filled($customer->business_name) && $this->filled($customer->business_type);

        return new KycRequirement(
            key: 'businessDetails',
            label: 'Business details recorded',
            satisfied: $satisfied,
            required: $profile->requires_business_details,
            detail: $satisfied ? null : 'Record the business name and what the business does.',
        );
    }

    private function bankAccount(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        return new KycRequirement(
            key: 'bankAccount',
            label: 'Bank or mobile money account on file',
            satisfied: $this->hasBankAccount($customer),
            required: $profile->requires_bank_account,
            detail: $this->hasBankAccount($customer)
                ? null
                : 'Record a bank account, or a mobile money wallet, for disbursement and collection.',
        );
    }

    private function cardDetails(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        return new KycRequirement(
            key: 'cardDetails',
            label: 'Bank card recorded',
            satisfied: $customer->card_last_four !== null,
            required: $profile->requires_card_details,
            /* Says what is stored, because officers ask. See
               RegisterCustomerAction: the PAN never reaches the database. */
            detail: 'Only the last four digits are stored.',
        );
    }

    private function guarantors(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $count = $customer->guarantors()->count();
        $minimum = $profile->min_guarantors;

        return new KycRequirement(
            key: 'guarantors',
            label: $minimum > 0
                ? sprintf('At least %d guarantor%s', $minimum, $minimum === 1 ? '' : 's')
                : 'Guarantors',
            satisfied: $count >= $minimum,
            required: $minimum > 0,
            detail: sprintf('%d on file.', $count),
        );
    }

    private function nextOfKin(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $count = $customer->nextOfKin()->count();
        $minimum = $profile->min_next_of_kin;

        return new KycRequirement(
            key: 'nextOfKin',
            label: $minimum > 0
                ? sprintf('At least %d next of kin', $minimum)
                : 'Next of kin',
            satisfied: $count >= $minimum,
            required: $minimum > 0,
            detail: sprintf('%d on file.', $count),
        );
    }

    private function category(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        return new KycRequirement(
            key: 'categoryAssigned',
            label: 'Customer category assigned',
            satisfied: $customer->customer_category_id !== null,
            required: $profile->requires_customer_category,
            detail: $customer->customer_category_id === null
                ? 'The category decides which loan products this customer may take.'
                : null,
        );
    }

    private function faceVerification(Customer $customer, AccountTypeRequirement $profile): KycRequirement
    {
        $satisfied = $customer->face_verified_at !== null;

        return new KycRequirement(
            key: 'faceVerified',
            label: 'Face liveness verified',
            satisfied: $satisfied,
            required: $profile->requires_face_verification,
            detail: $satisfied
                ? null
                /* Named as a place the officer can go, because this is the one
                   step that is expected to happen after the form is saved and
                   possibly on another device. */
                : 'Open the customer and run Face Verification. This can be done later, from any signed-in device.',
        );
    }

    /* ------------------------------------------------------------ parts --- */

    /**
     * Bank details on the related record, or the mirrored columns, or a mobile
     * money wallet.
     *
     * Three places because all three are legitimate. `customer_bank_details`
     * is the record of account; the columns on `customers` are its searchable
     * mirror (see the 2026_08_02 migration); and a customer with no bank at
     * all — common — settles through a wallet, which is an account for this
     * purpose even though it is not a bank one.
     */
    private function hasBankAccount(Customer $customer): bool
    {
        return $customer->bankDetails()->exists()
            || $this->filled($customer->account_number)
            || $this->filled($customer->wallet_number);
    }

    /**
     * @return list<string>
     */
    private function identityDocumentsHeld(Customer $customer): array
    {
        $documents = [
            'National ID' => $customer->nida_number ?? $customer->national_id_number,
            'voter ID' => $customer->voter_id_number,
            'driving licence' => $customer->driver_licence_number,
            'passport' => $customer->passport_number,
            'work ID' => $customer->work_id_number,
        ];

        $held = [];

        foreach ($documents as $label => $value) {
            if ($this->filled($value)) {
                $held[] = $label;
            }
        }

        return $held;
    }

    private function filled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }
}
