<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AccountTypeRequirement;
use App\Models\MasterData\AccountType;
use Illuminate\Database\Seeder;

/**
 * What each shipped account type asks of a customer.
 *
 * A STARTING POINT, like MasterDataSeeder — every flag here is editable from
 * the administration screen, and an account type the institution adds later
 * inherits the default profile until somebody configures it.
 *
 * The two shipped types genuinely differ, which is the point:
 *
 *   LOAN ACCOUNT     Somebody is about to be lent money. The institution needs
 *                    to know where they work, what they earn, who will vouch
 *                    for them, who to contact, and which category they fall in
 *                    — the category is what decides which products they may
 *                    take, so a loan account without one cannot be priced.
 *
 *   SAVINGS ACCOUNT  Somebody is depositing their own money. Asking for a
 *                    guarantor and a check number to open one is the kind of
 *                    friction that loses the customer, and none of it is used.
 *                    An account to pay into, and an identity, is the whole
 *                    requirement.
 *
 * NEITHER REQUIRES NIDA OR OTP. Not a relaxation — both integrations are
 * absent, and a requirement that cannot be met is a customer who can never be
 * completed. See KycEvaluator and config/kyc.php.
 *
 * The default row itself is created by the 2026_08_26 migration, not here: the
 * resolver falls back to it on every request, so it is an invariant of the
 * schema rather than seed data.
 */
final class AccountTypeRequirementSeeder extends Seeder
{
    public function run(): void
    {
        $this->profile('LOAN', [
            'requires_employment_details' => true,
            'requires_bank_account' => true,
            /* One, matching LoanEligibilityChecker::MINIMUM_GUARANTORS. The
               loan engine refuses an application without a guarantor, so a
               registration that does not collect one produces a customer who
               is "complete" and still cannot borrow — which is precisely the
               disconnect this work is closing. */
            'min_guarantors' => 1,
            'min_next_of_kin' => 1,
            'requires_customer_category' => true,
            'requires_marital_status' => true,
            'requires_address' => true,
            'requires_identity_document' => true,
            /* Category documents stay advisory — see the 2026_08_30_000005
               migration. Explicit rather than relying on the column default,
               so the intent is readable here. */
            'requires_category_documents' => false,
            'requires_face_verification' => true,
            'guidance' => 'A loan account needs employment and income details, an account to disburse to, a guarantor, a next of kin and a customer category.',
        ]);

        $this->profile('SAVINGS', [
            'requires_bank_account' => true,
            'requires_address' => true,
            'requires_identity_document' => true,
            /* Category documents stay advisory — see the 2026_08_30_000005
               migration. Explicit rather than relying on the column default,
               so the intent is readable here. */
            'requires_category_documents' => false,
            'requires_face_verification' => true,
            'guidance' => 'A savings account needs an identity document, an address and an account to pay into.',
        ]);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function profile(string $accountTypeCode, array $attributes): void
    {
        $accountType = AccountType::query()->where('code', $accountTypeCode)->first();

        /*
         * Silently skipped rather than failed. An institution is free to
         * remove a shipped account type, and a seeder that dies because
         * "SAVINGS" is gone would block every subsequent seeder for a row
         * nobody wants.
         */
        if ($accountType === null) {
            return;
        }

        AccountTypeRequirement::query()->updateOrCreate(
            ['account_type_id' => $accountType->getKey()],
            $attributes,
        );
    }
}
