<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Guarantors of a loan application, used both by the first application form (guarantors sent with the application)
 * and by the Loan Sponser step of an application already registered.
 *
 * An entry is one of three things:
 *  - `guarantor_id`: a guarantor the borrower already has (profile or earlier loan);
 *  - `customer_id` + `relationship`: another customer of the borrower's branch, whose KYC details are copied;
 *  - the full guarantor form (first/last name, phone, relationship …): somebody new.
 */
class LoanGuarantors
{
    private const COPIED_FROM_CUSTOMER = ['first_name', 'middle_name', 'last_name', 'phone', 'gender', 'marital_status', 'id_number', 'region_id', 'district', 'ward', 'street'];

    /**
     * Import Guarantor options, as select groups: the borrower's saved guarantors ("g:<id>") and the other customers of
     * the borrower's branch ("c:<id>"). The borrower and anyone whose phone is already taken are left out.
     *
     * @param  iterable<string>  $takenPhones
     * @return list<array{label: string, options: list<array{value: string, label: string}>}>
     */
    public function candidates(Customer $borrower, iterable $takenPhones = [], ?Loan $loan = null): array
    {
        $taken = collect($takenPhones)->filter()->values()->all();

        $saved = $borrower->guarantors()
            ->when($loan, fn ($query) => $query->where(fn ($query) => $query->whereNull('loan_id')->orWhere('loan_id', '!=', $loan->id)))
            ->whereNotIn('phone', $taken)
            ->orderByDesc('id')
            ->get()
            ->unique('phone')
            ->map(fn (Guarantor $guarantor): array => [
                'value' => "g:{$guarantor->id}",
                'label' => trim("{$guarantor->first_name} {$guarantor->last_name}")." / {$guarantor->phone}",
            ])->values()->all();

        $customers = $this->branchCustomers($borrower)
            ->whereNotIn('phone', array_merge($taken, [$borrower->phone]))
            ->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'phone'])
            ->map(fn (Customer $customer): array => [
                'value' => "c:{$customer->id}",
                'label' => $this->fullName($customer)." / {$customer->phone}",
            ])->values()->all();

        return array_values(array_filter([
            ['label' => 'Saved guarantors of this customer', 'options' => $saved],
            ['label' => 'Customers of this branch', 'options' => $customers],
        ], fn (array $group): bool => $group['options'] !== []));
    }

    /**
     * Validates one entry for this borrower. Error keys carry `$prefix` (e.g. "guarantors.0.") so a list sent with the
     * application reports which row is wrong.
     *
     * @param  array<string, mixed>  $input
     * @return array{source: Guarantor|null, attributes: array<string, mixed>, phone: string, key: string}
     */
    public function resolve(Customer $borrower, array $input, string $prefix = ''): array
    {
        if (filled($input['guarantor_id'] ?? null)) {
            $data = $this->validate($input, [
                'guarantor_id' => ['required', Rule::exists('guarantors', 'id')->where('customer_id', $borrower->id)],
            ], $prefix);
            $source = Guarantor::findOrFail($data['guarantor_id']);

            return ['source' => $source, 'attributes' => [], 'phone' => (string) $source->phone, 'key' => 'guarantor_id'];
        }

        if (filled($input['customer_id'] ?? null)) {
            $data = $this->validate($input, [
                'customer_id' => ['required', Rule::exists('customers', 'id')
                    ->where('company_id', $borrower->company_id)
                    ->where('branch_id', $borrower->branch_id)
                    ->whereNot('id', $borrower->id)],
                'relationship' => ['required', 'string', 'max:50'],
            ], $prefix, ['customer_id' => 'customer']);
            $person = Customer::findOrFail($data['customer_id']);

            return [
                'source' => null,
                'attributes' => $person->only(self::COPIED_FROM_CUSTOMER) + ['relationship' => $data['relationship']],
                'phone' => (string) $person->phone,
                'key' => 'customer_id',
            ];
        }

        $data = $this->validate($input, [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'numeric', 'digits_between:9,12'],
            'gender' => ['nullable', 'in:male,female'],
            'marital_status' => ['nullable', Rule::in(Customer::MARITAL_STATUSES)],
            'id_number' => ['nullable', 'string', 'max:50'],
            'relationship' => ['required', 'string', 'max:50'],
            'region_id' => ['nullable', 'exists:regions,id'],
            'district' => ['nullable', 'string', 'max:100'],
            'ward' => ['nullable', 'string', 'max:100'],
            'street' => ['nullable', 'string', 'max:100'],
        ], $prefix);

        if ((string) $data['phone'] === (string) $borrower->phone) {
            throw ValidationException::withMessages(["{$prefix}phone" => 'The customer cannot guarantee their own loan']);
        }

        return ['source' => null, 'attributes' => $data, 'phone' => (string) $data['phone'], 'key' => 'phone'];
    }

    /**
     * Validates a whole list sent with the application, including the same person twice.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{source: Guarantor|null, attributes: array<string, mixed>, phone: string, key: string}>
     */
    public function resolveMany(Customer $borrower, array $entries): array
    {
        $resolved = [];
        $phones = [];

        foreach (array_values($entries) as $index => $entry) {
            $item = $this->resolve($borrower, (array) $entry, "guarantors.{$index}.");

            if (in_array($item['phone'], $phones, true)) {
                throw ValidationException::withMessages(["guarantors.{$index}.{$item['key']}" => 'This guarantor is listed twice']);
            }

            $phones[] = $item['phone'];
            $resolved[] = $item;
        }

        return $resolved;
    }

    /**
     * @param  array{source: Guarantor|null, attributes: array<string, mixed>, phone: string, key: string}  $item
     */
    public function attach(Loan $loan, array $item, string $prefix = ''): void
    {
        if ($loan->guarantors()->where('phone', $item['phone'])->exists()) {
            throw ValidationException::withMessages(["{$prefix}{$item['key']}" => 'This guarantor is already on this loan']);
        }

        $source = $item['source'];

        // A profile guarantor is attached as-is; one already backing another loan is copied, so that loan keeps its guarantor.
        if ($source !== null) {
            $source->loan_id === null
                ? $source->update(['loan_id' => $loan->id])
                : $source->replicate()->fill(['loan_id' => $loan->id])->save();

            return;
        }

        $loan->customer->guarantors()->create($item['attributes'] + ['loan_id' => $loan->id]);
    }

    /**
     * @return Builder<Customer>
     */
    private function branchCustomers(Customer $borrower)
    {
        return Customer::query()
            ->where('company_id', $borrower->company_id)
            ->where('branch_id', $borrower->branch_id)
            ->whereKeyNot($borrower->id);
    }

    private function fullName(Customer $customer): string
    {
        return trim((string) preg_replace('/\s+/', ' ', "{$customer->first_name} {$customer->middle_name} {$customer->last_name}"));
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    private function validate(array $input, array $rules, string $prefix, array $attributes = []): array
    {
        $validator = Validator::make($input, $rules, [], $attributes);

        if ($validator->fails()) {
            throw ValidationException::withMessages(
                collect($validator->errors()->messages())->mapWithKeys(fn (array $messages, string $key): array => ["{$prefix}{$key}" => $messages])->all(),
            );
        }

        return $validator->validated();
    }
}
