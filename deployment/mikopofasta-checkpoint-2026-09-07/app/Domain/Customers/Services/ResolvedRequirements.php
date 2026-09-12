<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Models\AccountTypeRequirement;
use App\Models\Customer;
use Carbon\CarbonImmutable;

/**
 * What one customer must actually supply — the answer, not the rows it came
 * from.
 *
 * ## Why this exists
 *
 * Requirements are stored in up to three rows: a profile for the customer's
 * type, a profile for the account type they are opening, and the default. None
 * of them is the answer on its own. The answer is those three composed, most
 * specific first, field by field — and every consumer needs the same composed
 * answer, because a customer validated against one reading and judged complete
 * against another is precisely the bug this replaces.
 *
 * So the resolver returns one of these rather than a model. A model would be
 * one of the three rows, and callers would have to know which and how to fall
 * back — which is the drift the AccountTypeRequirementResolver docblock warns
 * about, moved one level up.
 *
 * ## The composition rule
 *
 * NULL means "no opinion, ask the next profile down". Any non-null value wins
 * for that field alone. So a customer type can say "no employment details"
 * without restating the other twelve flags, and a flag nobody has an opinion
 * about lands on the default — never on "not required", which in a KYC system
 * is the one wrong default.
 *
 * Readonly, because it is an answer about a moment. Ask again if the
 * configuration changes.
 */
final readonly class ResolvedRequirements
{
    /**
     * @param list<string> $sources Which profiles contributed, most specific
     *                              first — for the admin screen to explain
     *                              where an answer came from.
     */
    private function __construct(
        public bool $requiresEmploymentDetails,
        public bool $requiresBusinessDetails,
        public bool $requiresBankAccount,
        public bool $requiresCardDetails,
        public int $minGuarantors,
        public int $minNextOfKin,
        public bool $requiresCustomerCategory,
        public bool $requiresMaritalStatus,
        public bool $requiresAddress,
        public bool $requiresIdentityDocument,
        public bool $requiresCategoryDocuments,
        public ?CarbonImmutable $categoryDocumentsEnforcedFrom,
        public bool $requiresFaceVerification,
        public bool $requiresNidaVerification,
        public bool $requiresOtpVerification,
        public ?string $guidance,
        public array $sources,
    ) {}

    /**
     * Composes the profiles, most specific first.
     *
     * The LAST profile must be the default, and it must answer everything —
     * `AccountTypeRequirementResolver::default()` throws rather than return a
     * row that does not exist, so by the time this is called there is always a
     * floor. The `?? false` / `?? 0` fallbacks below are therefore unreachable
     * in practice; they are here because a nullable column has no way to
     * promise that at the type level, and a KYC rule should degrade to
     * "required is required, counts are zero" rather than to a TypeError.
     *
     * @param list<AccountTypeRequirement> $profiles most specific first
     */
    public static function compose(array $profiles): self
    {
        $pick = static function (string $column) use ($profiles): mixed {
            foreach ($profiles as $profile) {
                $value = $profile->getAttribute($column);
                if ($value !== null) {
                    return $value;
                }
            }

            return null;
        };

        return new self(
            requiresEmploymentDetails: (bool) ($pick('requires_employment_details') ?? false),
            requiresBusinessDetails: (bool) ($pick('requires_business_details') ?? false),
            requiresBankAccount: (bool) ($pick('requires_bank_account') ?? false),
            requiresCardDetails: (bool) ($pick('requires_card_details') ?? false),
            minGuarantors: (int) ($pick('min_guarantors') ?? 0),
            minNextOfKin: (int) ($pick('min_next_of_kin') ?? 0),
            requiresCustomerCategory: (bool) ($pick('requires_customer_category') ?? false),
            requiresMaritalStatus: (bool) ($pick('requires_marital_status') ?? false),
            requiresAddress: (bool) ($pick('requires_address') ?? false),
            requiresIdentityDocument: (bool) ($pick('requires_identity_document') ?? false),
            requiresCategoryDocuments: (bool) ($pick('requires_category_documents') ?? false),
            categoryDocumentsEnforcedFrom: $pick('category_documents_enforced_from'),
            requiresFaceVerification: (bool) ($pick('requires_face_verification') ?? false),
            requiresNidaVerification: (bool) ($pick('requires_nida_verification') ?? false),
            requiresOtpVerification: (bool) ($pick('requires_otp_verification') ?? false),
            /* Guidance is prose shown above the step. The most specific one
               that HAS something to say wins; it does not concatenate. */
            guidance: $pick('guidance'),
            sources: array_map(
                static fn (AccountTypeRequirement $p): string => match (true) {
                    $p->customer_category_id !== null => 'customer_category:'.$p->customer_category_id,
                    $p->account_type_id !== null => 'account_type:'.$p->account_type_id,
                    default => 'default',
                },
                $profiles,
            ),
        );
    }

    /**
     * Whether THIS customer's category documents block, given when they were
     * registered.
     *
     * Moved here from AccountTypeRequirement so the question is asked of the
     * composed answer rather than of whichever row happened to be fetched —
     * the flag and the cutoff can now come from different profiles, and asking
     * one row would read one of them out of context.
     *
     * The switch has three settings, and the middle one is why the date exists:
     *
     *   flag false              → nothing blocks.
     *   flag true, cutoff null  → blocks for everyone, existing customers too.
     *   flag true, cutoff set   → blocks only registrations on or after it.
     *
     * A customer with no creation timestamp — which should not happen — is
     * treated as pre-cutoff, because the failure mode of guessing the other way
     * is a branch that cannot lend.
     */
    public function categoryDocumentsApplyTo(Customer $customer): bool
    {
        if (! $this->requiresCategoryDocuments) {
            return false;
        }

        if ($this->categoryDocumentsEnforcedFrom === null) {
            return true;
        }

        $registeredAt = $customer->created_at;

        return $registeredAt !== null
            && $registeredAt->startOfDay()->greaterThanOrEqualTo($this->categoryDocumentsEnforcedFrom->startOfDay());
    }
}
