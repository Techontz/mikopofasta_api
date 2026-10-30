<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerBankDetail;
use App\Models\CustomerDuplicateReview;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Duplicate customer prevention (enhancement §2.1). Every check runs against all customers of the company, every branch
 * and soft-deleted ones included, whatever the registering employee may view.
 *
 * - NIDA and phone are the person's identity: a match blocks the registration outright.
 * - A bank account, check number or the three names alone do not prove it is the same person: a match holds the
 *   registration for a KYC approver ({@see CustomerDuplicateReview}), who registers it (the authorized exception) or
 *   rejects it as a duplicate.
 *
 * Values are compared normalised (case, spaces, dashes, phone prefix), so `0754 123 456` and `+255754123456` collide.
 * Messages never name the existing customer: the registering employee may not be allowed to see them.
 */
class DuplicateCustomerCheck
{
    /**
     * @var list<string>
     */
    public const BLOCKING = ['nida', 'phone'];

    /**
     * @var array<string, array{0: string, 1: string}>
     */
    private const MESSAGES = [
        'nida' => ['nidaNumber', 'A customer with this NIDA number is already registered.'],
        'phone' => ['phone', 'A customer with this phone number is already registered.'],
        'bank_account' => ['bankDetails.accountNumber', 'This bank account is already linked to another customer.'],
        'check_number' => ['checkNumber', 'This check number is already registered to another customer.'],
    ];

    /**
     * Existing customers sharing an identifier with the payload, with what matched.
     *
     * @param  array<string, mixed>  $payload  registration payload (camelCase)
     * @param  list<string>|null  $only  limit the checks to these reasons
     * @return list<array{customer_id: int, reasons: list<string>}>
     */
    public function matches(int $companyId, array $payload, ?int $ignoreCustomerId = null, ?array $only = null): array
    {
        $found = [];
        $add = function (iterable $ids, string $reason) use (&$found): void {
            foreach ($ids as $id) {
                $found[(int) $id][] = $reason;
            }
        };
        $wants = fn (string $reason): bool => $only === null || in_array($reason, $only, true);
        $customers = fn (): Builder => Customer::withTrashed()->where('company_id', $companyId)
            ->when($ignoreCustomerId, fn (Builder $query, int $id) => $query->whereKeyNot($id));

        if ($wants('nida') && ($nida = self::digits($payload['nidaNumber'] ?? null)) !== null) {
            $add($customers()->whereRaw(self::digitsSql('nida_number').' = ?', [$nida])->pluck('id'), 'nida');
        }

        if ($wants('phone') && ($phone = self::phone($payload['phone'] ?? null)) !== null) {
            $add($customers()->whereRaw('RIGHT('.self::digitsSql('phone').', 9) = ?', [$phone])->pluck('id'), 'phone');
        }

        if ($wants('bank_account') && ($account = self::code(Arr::get($payload, 'bankDetails.accountNumber'))) !== null) {
            $onCustomer = $customers()->whereRaw(self::codeSql('account_number').' = ?', [$account])->pluck('id');
            $onDetails = CustomerBankDetail::query()->whereRaw(self::codeSql('account_number').' = ?', [$account])
                ->whereIn('customer_id', $customers()->select('id'))->pluck('customer_id');
            $add($onCustomer->merge($onDetails)->unique(), 'bank_account');
        }

        if ($wants('check_number')) {
            $checks = array_values(array_unique(array_filter([
                self::code($payload['checkNumber'] ?? null),
                self::code(Arr::get($payload, 'bankDetails.checkNumber')),
            ])));
            if ($checks !== []) {
                $placeholders = implode(',', array_fill(0, count($checks), '?'));
                $onCustomer = $customers()->whereRaw(self::codeSql('check_number')." in ({$placeholders})", $checks)->pluck('id');
                $onDetails = CustomerBankDetail::query()->whereRaw(self::codeSql('check_number')." in ({$placeholders})", $checks)
                    ->whereIn('customer_id', $customers()->select('id'))->pluck('customer_id');
                $add($onCustomer->merge($onDetails)->unique(), 'check_number');
            }
        }

        if ($wants('full_name') && filled($payload['firstName'] ?? null) && filled($payload['lastName'] ?? null)) {
            $add($customers()
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [self::name($payload['firstName'])])
                ->whereRaw("LOWER(TRIM(COALESCE(middle_name, ''))) = ?", [self::name($payload['middleName'] ?? '')])
                ->whereRaw('LOWER(TRIM(last_name)) = ?', [self::name($payload['lastName'])])
                ->pluck('id'), 'full_name');
        }

        ksort($found);

        return array_map(fn (int $id, array $reasons): array => ['customer_id' => $id, 'reasons' => array_values(array_unique($reasons))], array_keys($found), $found);
    }

    /**
     * A registration may go ahead: no NIDA or phone match, and every other match was already seen by the approver
     * releasing it. Call inside the registration transaction, after locking the company row, so two registrations of
     * the same person cannot both pass.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws ValidationException on a NIDA or phone match
     * @throws PossibleDuplicateCustomer on any other match not yet reviewed
     */
    public function assertRegistrable(int $companyId, array $payload, ?CustomerDuplicateReview $release = null): void
    {
        $matches = $this->matches($companyId, $payload);
        $this->throwBlocking($matches, self::BLOCKING);

        $reviewed = collect($release?->matches ?? [])->pluck('customer_id')->map(fn ($id): int => (int) $id)->all();
        $unreviewed = array_values(array_filter($matches, fn (array $match): bool => ! in_array($match['customer_id'], $reviewed, true)));
        if ($unreviewed !== []) {
            throw new PossibleDuplicateCustomer($matches);
        }
    }

    /**
     * An update may not give a customer another customer's NIDA, phone, bank account or check number. Only identifiers
     * the update changes are checked, so a customer already sharing an account from before is not locked out of edits.
     *
     * @param  array<string, mixed>  $payload  the merged payload being saved
     * @param  array<string, mixed>  $current  the customer's values before the update ({@see CustomerRegistrar::payloadFor})
     *
     * @throws ValidationException
     */
    public function assertUpdatable(Customer $customer, array $payload, array $current): void
    {
        $changed = array_values(array_filter([
            self::digits($payload['nidaNumber'] ?? null) !== self::digits($current['nidaNumber'] ?? null) ? 'nida' : null,
            self::phone($payload['phone'] ?? null) !== self::phone($current['phone'] ?? null) ? 'phone' : null,
            self::code(Arr::get($payload, 'bankDetails.accountNumber')) !== self::code(Arr::get($current, 'bankDetails.accountNumber')) ? 'bank_account' : null,
            self::code($payload['checkNumber'] ?? null) !== self::code($current['checkNumber'] ?? null)
                || self::code(Arr::get($payload, 'bankDetails.checkNumber')) !== self::code(Arr::get($current, 'bankDetails.checkNumber')) ? 'check_number' : null,
        ]));
        if ($changed === []) {
            return;
        }

        $this->throwBlocking($this->matches((int) $customer->company_id, $payload, (int) $customer->id, $changed), $changed);
    }

    /**
     * Hold a registration for review instead of creating the customer. A registration a reviewer already rejected as a
     * duplicate of the same customers is refused, not queued again.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array{customer_id: int, reasons: list<string>}>  $matches
     *
     * @throws ValidationException
     */
    public function hold(array $payload, Employee $actor, array $matches): CustomerDuplicateReview
    {
        $ids = collect($matches)->pluck('customer_id')->sort()->values()->all();
        $confirmed = CustomerDuplicateReview::query()
            ->where('company_id', $actor->company_id)
            ->where('status', CustomerDuplicateReview::STATUS_REJECTED)
            ->get(['id', 'matches'])
            ->contains(fn (CustomerDuplicateReview $review): bool => collect($review->matches)->pluck('customer_id')->map(fn ($id): int => (int) $id)->sort()->values()->all() === $ids);
        if ($confirmed) {
            throw ValidationException::withMessages(['customer' => 'This registration was reviewed and confirmed as a duplicate of an existing customer. It cannot be registered again.']);
        }

        $pending = CustomerDuplicateReview::query()
            ->where('company_id', $actor->company_id)
            ->where('status', CustomerDuplicateReview::STATUS_PENDING)
            ->where('requested_by', $actor->id)
            ->get()
            ->first(fn (CustomerDuplicateReview $review): bool => collect($review->matches)->pluck('customer_id')->map(fn ($id): int => (int) $id)->sort()->values()->all() === $ids);
        if ($pending !== null) {
            $pending->forceFill(['payload' => $payload, 'matches' => $matches])->save();

            return $pending;
        }

        return CustomerDuplicateReview::create([
            'company_id' => $actor->company_id,
            'branch_id' => is_numeric($payload['branchId'] ?? null) ? (int) $payload['branchId'] : $actor->branch_id,
            'payload' => $payload,
            'matches' => $matches,
            'status' => CustomerDuplicateReview::STATUS_PENDING,
            'requested_by' => $actor->id,
        ]);
    }

    /**
     * @param  list<array{customer_id: int, reasons: list<string>}>  $matches
     * @param  list<string>  $reasons
     *
     * @throws ValidationException
     */
    private function throwBlocking(array $matches, array $reasons): void
    {
        $errors = [];
        foreach ($matches as $match) {
            foreach (array_intersect($match['reasons'], $reasons) as $reason) {
                [$key, $message] = self::MESSAGES[$reason];
                $errors[$key] = $message;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function digits(mixed $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits === '' ? null : $digits;
    }

    /**
     * The subscriber part of a Tanzanian number: the last nine digits, whatever the 0 / 255 / +255 prefix.
     */
    public static function phone(mixed $value): ?string
    {
        $digits = self::digits($value);

        return $digits === null || strlen($digits) < 9 ? $digits : substr($digits, -9);
    }

    /**
     * An account or check number: upper case, letters and digits only.
     */
    public static function code(mixed $value): ?string
    {
        $code = preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) $value));

        return $code === '' ? null : $code;
    }

    private static function name(mixed $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    private static function digitsSql(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '+', ''), '.', ''), '/', '')";
    }

    private static function codeSql(string $column): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE({$column}, ''), ' ', ''), '-', ''), '.', ''), '/', ''))";
    }
}
