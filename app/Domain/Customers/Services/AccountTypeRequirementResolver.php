<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Exceptions\ConfigurationException;
use App\Models\AccountTypeRequirement;
use App\Models\Customer;
use Illuminate\Support\Collection;

/**
 * Which requirement profile governs a customer — the single place that decides.
 *
 * Every consumer asks this rather than querying `account_type_requirements`
 * itself: the registration validator, the KYC evaluator, the wizard's
 * requirements endpoint and the profile's progress panel. If they each did
 * their own lookup they would each need their own answer to "what if the
 * account type has no profile", and the four answers would drift — which in a
 * KYC system means a customer validated against one rule and judged against
 * another.
 *
 * The fallback is the default row (`account_type_id IS NULL`), created by the
 * migration. See ConfigurationException::registrationRequirementsMissing for
 * why its absence is fatal rather than defaulted around.
 */
final class AccountTypeRequirementResolver
{
    /**
     * Resolved profiles, keyed by account type id (0 for the default).
     *
     * Registration reads the same profile several times in one request — the
     * validator, the action and the evaluator all ask — and the wizard's
     * requirements endpoint reads every profile at once. Memoising per request
     * keeps that at one query without introducing a cache anything has to
     * remember to invalidate.
     *
     * @var array<int, AccountTypeRequirement>
     */
    private array $resolved = [];

    /**
     * Composed answers, keyed `categoryId:accountTypeId`.
     *
     * Same reasoning as $resolved: registration composes the same pair several
     * times in one request — the validator, the action and the evaluator all
     * ask — and composing walks up to three rows each time.
     *
     * @var array<string, ResolvedRequirements>
     */
    private array $composed = [];

    /**
     * The composed answer for a customer type and an account type.
     *
     * THIS IS THE ONE METHOD CONSUMERS SHOULD CALL. It returns what the
     * customer must supply, not the row it was read from — see
     * ResolvedRequirements for why the distinction matters.
     *
     * Resolution is most-specific-first and composed field by field:
     *
     *   1. the customer type's profile   (who they are)
     *   2. the account type's profile    (what they are opening)
     *   3. the default                   (the floor, always present)
     *
     * A customer type that says nothing about a flag inherits the account
     * type's answer, and a flag nobody has an opinion on lands on the default.
     * Neither can silently relax a rule to "not required" by omission, because
     * omission means NULL and NULL defers rather than decides.
     */
    public function resolve(?int $accountTypeId, ?int $customerCategoryId): ResolvedRequirements
    {
        $key = ($customerCategoryId ?? 0).':'.($accountTypeId ?? 0);

        if (isset($this->composed[$key])) {
            return $this->composed[$key];
        }

        $profiles = [];

        if ($customerCategoryId !== null) {
            $categoryProfile = AccountTypeRequirement::query()
                ->where('customer_category_id', $customerCategoryId)
                ->first();

            if ($categoryProfile !== null) {
                $profiles[] = $categoryProfile;
            }
        }

        if ($accountTypeId !== null) {
            $accountProfile = AccountTypeRequirement::query()
                ->where('account_type_id', $accountTypeId)
                ->whereNull('customer_category_id')
                ->first();

            if ($accountProfile !== null) {
                $profiles[] = $accountProfile;
            }
        }

        // Always last, always complete. `default()` throws if it is missing.
        $profiles[] = $this->default();

        return $this->composed[$key] = ResolvedRequirements::compose($profiles);
    }

    public function resolveForCustomer(Customer $customer): ResolvedRequirements
    {
        return $this->resolve($customer->account_type_id, $customer->customer_category_id);
    }

    /**
     * The single account-type row, uncomposed.
     *
     * Retained for the admin screen, which edits one profile at a time and
     * must see exactly what that profile stores rather than what it resolves
     * to. Everything that judges a customer should call `resolve()` instead.
     */
    public function for(?int $accountTypeId): AccountTypeRequirement
    {
        $key = $accountTypeId ?? 0;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $profile = $accountTypeId === null
            ? null
            : AccountTypeRequirement::query()
                ->where('account_type_id', $accountTypeId)
                ->whereNull('customer_category_id')
                ->first();

        /*
         * An account type with no profile of its own falls back to the default
         * rather than to "nothing required". An account type created this
         * morning from the admin screen is exactly that case, and it must not
         * be the one route by which a customer reaches KYC-complete having
         * been asked for nothing.
         */
        return $this->resolved[$key] = $profile ?? $this->default();
    }

    public function forCustomer(Customer $customer): AccountTypeRequirement
    {
        return $this->for($customer->account_type_id);
    }

    /**
     * The profile stored against one customer type, if it has one.
     *
     * Null is a real answer here — "this type states no requirements of its
     * own" — and the admin screen renders that differently from a type whose
     * every flag is off.
     */
    public function forCategory(int $customerCategoryId): ?AccountTypeRequirement
    {
        return AccountTypeRequirement::query()
            ->where('customer_category_id', $customerCategoryId)
            ->first();
    }

    /**
     * Every profile, for the wizard's requirements endpoint.
     *
     * Keyed by account type id as a string, with `null` for the default, so the
     * client can look one up by the value in its own dropdown without matching
     * on codes.
     *
     * @return Collection<int, AccountTypeRequirement>
     */
    public function all(): Collection
    {
        return AccountTypeRequirement::query()
            ->with('accountType')
            /* Account-type and default profiles only. Customer-type profiles
               are a different axis and belong on their own screen — mixing
               them here is what produced two controls for one setting. */
            ->whereNull('customer_category_id')
            ->orderByRaw('account_type_id IS NOT NULL')
            ->get();
    }

    public function default(): AccountTypeRequirement
    {
        return AccountTypeRequirement::query()->whereNull('account_type_id')->first()
            ?? throw ConfigurationException::registrationRequirementsMissing();
    }
}
