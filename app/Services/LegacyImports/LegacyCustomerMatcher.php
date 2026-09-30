<?php

namespace App\Services\LegacyImports;

use App\Models\Customer;
use App\Models\LegacyImportRow;
use App\Services\Customers\HistoricalNameMatcher;
use Illuminate\Support\Collection;

/**
 * Finds the existing customer an old-system row belongs to, using the strongest information the file has:
 *
 *  1. the phone number (Loan File only) — the customer holding that number, provided the name agrees;
 *  2. the name within the row's branch — the same name exactly, or the same person written with initials
 *     ("SHABAN D. MASONJO" = "SHABAN DAUD MASONJO", {@see HistoricalNameMatcher}), which is flagged for a second look.
 *
 * It never guesses. Two candidates, a phone held by someone with another name, the same name with a different phone, or
 * a name found only in another branch all leave the row unmatched with the reason, for an Admin to map by hand — so a
 * duplicate customer is never created, and a balance never lands on the wrong person.
 */
final class LegacyCustomerMatcher
{
    /** @var Collection<int, Customer> */
    private Collection $customers;

    /** @var array<string, list<int>> normalised phone => customer ids */
    private array $byPhone = [];

    /** @var array<string, list<int>> name key => customer ids */
    private array $byName = [];

    public function __construct(int $companyId)
    {
        $this->customers = Customer::query()
            ->where('company_id', $companyId)
            ->with('branch:id,name')
            ->get(['id', 'company_id', 'branch_id', 'customer_number', 'first_name', 'middle_name', 'last_name', 'phone', 'alternative_phone'])
            ->keyBy('id');

        foreach ($this->customers as $customer) {
            foreach ([$customer->phone, $customer->alternative_phone] as $phone) {
                if (($normalised = LegacyRowReader::phone($phone)) !== null) {
                    $this->byPhone[$normalised][] = $customer->id;
                }
            }
            $this->byName[LegacyRowReader::nameKey($customer->full_name)][] = $customer->id;
        }
    }

    public function find(int $customerId): ?Customer
    {
        return $this->customers->get($customerId);
    }

    /**
     * @return array{customer: Customer|null, method: string|null, status: string, message: string|null}
     */
    public function match(string $name, ?string $phone, int $branchId): array
    {
        $key = LegacyRowReader::nameKey($name);
        $phone = LegacyRowReader::phone($phone);

        if ($phone !== null && isset($this->byPhone[$phone])) {
            $holders = $this->customers->only(array_unique($this->byPhone[$phone]));
            $same = $holders->filter(fn (Customer $customer): bool => $this->sameName($name, $customer));

            if ($same->count() === 1) {
                $customer = $same->first();
                if ((int) $customer->branch_id !== $branchId) {
                    return $this->found($customer, LegacyImportRow::MATCH_PHONE, LegacyImportRow::STATUS_WARNING,
                        "Matched by phone to {$this->describe($customer)}, who is registered in another branch.");
                }

                return $this->found($customer, LegacyImportRow::MATCH_PHONE, $this->exact($name, $customer) ? LegacyImportRow::STATUS_VALID : LegacyImportRow::STATUS_WARNING,
                    $this->exact($name, $customer) ? null : "Matched by phone to {$this->describe($customer)}; the names are written differently.");
            }

            return $this->unmatched($same->isEmpty()
                ? "Phone {$phone} belongs to {$this->list($holders)}, whose name is not \"{$name}\". Map it by hand if it is the same person."
                : "Phone {$phone} belongs to more than one customer with this name: {$this->list($same)}. Map it by hand.");
        }

        $inBranch = $this->customers->filter(fn (Customer $customer): bool => (int) $customer->branch_id === $branchId);
        $exact = $this->customers->only(array_unique($this->byName[$key] ?? []))->filter(fn (Customer $customer): bool => (int) $customer->branch_id === $branchId);
        $candidates = $exact->isNotEmpty() ? $exact : $inBranch->filter(fn (Customer $customer): bool => $this->sameName($name, $customer));

        if ($candidates->count() > 1) {
            return $this->unmatched("More than one customer in this branch could be \"{$name}\": {$this->list($candidates)}. Map it by hand.");
        }

        if ($candidates->count() === 1) {
            $customer = $candidates->first();
            $theirs = LegacyRowReader::phone($customer->phone);
            if ($phone !== null && $theirs !== null && $theirs !== $phone) {
                return $this->unmatched("\"{$name}\" matches {$this->describe($customer)} by name, but their phone is {$theirs}, not {$phone}. Map it by hand if it is the same person.");
            }

            return $exact->isNotEmpty()
                ? $this->found($customer, LegacyImportRow::MATCH_NAME_BRANCH, LegacyImportRow::STATUS_VALID, null)
                : $this->found($customer, LegacyImportRow::MATCH_NAME_BRANCH, LegacyImportRow::STATUS_WARNING, "Matched by name to {$this->describe($customer)}; the names are written differently (initials).");
        }

        $elsewhere = $this->customers->only(array_unique($this->byName[$key] ?? []));
        if ($elsewhere->isNotEmpty()) {
            return $this->unmatched("No customer \"{$name}\" in this branch; the same name is registered in another branch: {$this->list($elsewhere)}. Map it by hand if it is the same person.");
        }

        return $this->unmatched("No customer \"{$name}\" was found in this branch. Map it to an existing customer or create a new customer.");
    }

    /**
     * The existing customers a row could belong to, most likely first (Map All suggestions): the phone holders with the same
     * name, the same name in the branch, the same person written with initials in the branch, the other holders of the
     * phone, then the same name in another branch.
     *
     * @return Collection<int, Customer>
     */
    public function candidates(string $name, ?string $phone, int $branchId, int $limit = 5): Collection
    {
        $key = LegacyRowReader::nameKey($name);
        $phone = LegacyRowReader::phone($phone);
        $holders = $phone !== null ? $this->customers->only(array_unique($this->byPhone[$phone] ?? [])) : collect();
        $named = $this->customers->only(array_unique($this->byName[$key] ?? []));
        $inBranch = $this->customers->filter(fn (Customer $customer): bool => (int) $customer->branch_id === $branchId);

        return collect()
            ->merge($holders->filter(fn (Customer $customer): bool => $this->sameName($name, $customer)))
            ->merge($named->filter(fn (Customer $customer): bool => (int) $customer->branch_id === $branchId))
            ->merge($inBranch->filter(fn (Customer $customer): bool => $this->sameName($name, $customer)))
            ->merge($holders)
            ->merge($named)
            ->unique(fn (Customer $customer): int => (int) $customer->id)
            ->take($limit)
            ->values();
    }

    /**
     * How a customer is shown when choosing one: name / customer number / phone / branch.
     */
    public static function label(Customer $customer): string
    {
        return trim("{$customer->full_name} / {$customer->customer_number} / ".($customer->phone ?? 'no phone').' / '.($customer->branch?->name ?? ''));
    }

    private function sameName(string $name, Customer $customer): bool
    {
        return $this->exact($name, $customer) || HistoricalNameMatcher::same($name, $customer->full_name);
    }

    private function exact(string $name, Customer $customer): bool
    {
        return LegacyRowReader::nameKey($name) === LegacyRowReader::nameKey($customer->full_name);
    }

    private function describe(Customer $customer): string
    {
        return trim("{$customer->full_name} ({$customer->customer_number}".($customer->branch ? ", {$customer->branch->name}" : '').')');
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    private function list(Collection $customers): string
    {
        return $customers->map(fn (Customer $customer): string => $this->describe($customer))->implode('; ');
    }

    /**
     * @return array{customer: Customer, method: string, status: string, message: string|null}
     */
    private function found(Customer $customer, string $method, string $status, ?string $message): array
    {
        return ['customer' => $customer, 'method' => $method, 'status' => $status, 'message' => $message];
    }

    /**
     * @return array{customer: null, method: null, status: string, message: string}
     */
    private function unmatched(string $message): array
    {
        return ['customer' => null, 'method' => null, 'status' => LegacyImportRow::STATUS_UNMATCHED, 'message' => $message];
    }
}
