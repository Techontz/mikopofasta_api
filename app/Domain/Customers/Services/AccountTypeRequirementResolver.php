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

    public function for(?int $accountTypeId): AccountTypeRequirement
    {
        $key = $accountTypeId ?? 0;

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $profile = $accountTypeId === null
            ? null
            : AccountTypeRequirement::query()->where('account_type_id', $accountTypeId)->first();

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
            ->orderByRaw('account_type_id IS NOT NULL')
            ->get();
    }

    public function default(): AccountTypeRequirement
    {
        return AccountTypeRequirement::query()->whereNull('account_type_id')->first()
            ?? throw ConfigurationException::registrationRequirementsMissing();
    }
}
