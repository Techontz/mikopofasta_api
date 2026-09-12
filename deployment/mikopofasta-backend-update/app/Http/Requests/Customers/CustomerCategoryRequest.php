<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\CategorySector;
use App\Domain\Customers\Enums\DynamicFieldType;
use App\Domain\Customers\Enums\RiskTier;
use App\Domain\Customers\Support\StructuredRegistrationField;
use App\Models\CustomerCategory;
use App\Support\MasterDataRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Mirrors the frontend's CategoryInputSchema
 * (features/admin/customer-categories/actions.ts), which picks name, code,
 * riskTier, sector, requiredDocuments, dynamicFormSchema and
 * requiresExtraApproval.
 *
 * The nested `dynamicFormSchema.*` rules validate the SHAPE of the field
 * definitions. What customers then submit against that schema is validated at
 * registration time by DynamicFormValidator — two different jobs.
 */
final class CustomerCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->route('category');
        $id = $category instanceof CustomerCategory ? $category->getKey() : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:120', Rule::unique('customer_categories', 'name')->ignore($id)->whereNull('deleted_at')],
            /*
             * OPTIONAL, and normally absent. The Super Administrator names a
             * customer type and nothing else; the code is an internal key they
             * should never have to invent, so ManageCustomerCategoryAction
             * derives one from the name when none is sent. Still accepted, and
             * still unique, for a caller that wants to choose it.
             */
            'code' => ['sometimes', 'string', 'min:2', 'max:40', Rule::unique('customer_categories', 'code')->ignore($id)->whereNull('deleted_at')],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            /* The heading the registration form puts over this type's own
               questions — "MTUMISHI WA UMMA" over WATUMISHI's. Null falls back
               to the name. */
            'formTitle' => ['sometimes', 'nullable', 'string', 'max:150'],
            /* `sometimes`, so a client that predates these leaves them as they
               are rather than silently switching a type off or reordering it. */
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            /*
             * All optional now. The create form asks for a NAME and nothing
             * else, so every one of these has to be either defaulted on create
             * or left alone on update — a `required` rule here would make a
             * name-only save impossible, and `present` would let an edit that
             * does not mention the documents silently empty them.
             */
            'riskTier' => ['sometimes', 'string', Rule::in(RiskTier::values())],
            'sector' => ['sometimes', 'string', Rule::in(CategorySector::values())],

            'requiredDocuments' => ['sometimes', 'array'],
            'requiredDocuments.*' => ['string', 'max:60'],
            /* Offered as a slot on the documents step, never blocking. */
            'optionalDocuments' => ['sometimes', 'nullable', 'array'],
            'optionalDocuments.*' => ['string', 'max:60'],

            'dynamicFormSchema' => ['sometimes', 'array'],
            'dynamicFormSchema.*.key' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'dynamicFormSchema.*.label' => ['required', 'string', 'max:150'],
            'dynamicFormSchema.*.type' => ['required', 'string', Rule::in(DynamicFieldType::values())],
            'dynamicFormSchema.*.required' => ['required', 'boolean'],
            'dynamicFormSchema.*.options' => ['sometimes', 'nullable', 'array'],
            'dynamicFormSchema.*.options.*' => ['string', 'max:100'],
            /*
             * Where a select gets its choices. An admin-managed list, named by
             * the same slug the master-data routes use, so a field configured
             * against "marital-statuses" offers whatever an administrator has
             * left active there — never a copy of the list frozen into this
             * category.
             */
            'dynamicFormSchema.*.dataSource' => ['sometimes', 'nullable', 'string', Rule::in(MasterDataRegistry::sources())],
            /* The field whose answer filters this one's list, and the field
               whose answer can make this one mandatory. Both name another
               field's KEY, and both are checked below to be a key this schema
               actually declares — a rule pointing at nothing would silently
               never fire. */
            'dynamicFormSchema.*.dependsOn' => ['sometimes', 'nullable', 'string', 'max:60'],
            'dynamicFormSchema.*.requiredWhen' => ['sometimes', 'nullable', 'array'],
            'dynamicFormSchema.*.requiredWhen.field' => ['required_with:dynamicFormSchema.*.requiredWhen', 'string', 'max:60'],
            'dynamicFormSchema.*.requiredWhen.equals' => ['required_with:dynamicFormSchema.*.requiredWhen', 'array', 'min:1'],
            'dynamicFormSchema.*.requiredWhen.equals.*' => ['string', 'max:100'],
            /*
             * Where the answer is kept. Absent means the JSON store, which is
             * the default and what every schema written before this existed
             * does. Naming one of the allowlisted registration fields puts the
             * answer in that customer column instead — see
             * StructuredRegistrationField for why this is declared rather than
             * inferred from the key.
             */
            'dynamicFormSchema.*.storesIn' => ['sometimes', 'nullable', 'string', Rule::in(StructuredRegistrationField::targets())],
            'dynamicFormSchema.*.placeholder' => ['sometimes', 'nullable', 'string', 'max:150'],
            'dynamicFormSchema.*.helpText' => ['sometimes', 'nullable', 'string', 'max:255'],
            'dynamicFormSchema.*.fullWidth' => ['sometimes', 'boolean'],

            'requiresExtraApproval' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The rules a per-row `Rule::in` cannot express, because they are about the
     * schema as a whole rather than one field in it.
     *
     * A DUPLICATE KEY silently loses an answer: two fields write to the same
     * place and the second wins, so the first is displayed, filled in and
     * discarded.
     *
     * A DANGLING REFERENCE never fires. `dependsOn` and `requiredWhen.field`
     * name another field by key; pointing at a key this schema does not declare
     * gives a cascade with no parent and a condition that can never be true,
     * and neither is visible by looking at the form.
     *
     * A DEPENDENT WITHOUT A PARENTED SOURCE is a misunderstanding worth
     * catching: only some lists have a parent to filter on, and declaring
     * `dependsOn` against a flat one would suggest a cascade that cannot
     * happen.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $schema = $this->input('dynamicFormSchema');

                if (! is_array($schema)) {
                    return;
                }

                $keys = [];

                foreach ($schema as $i => $field) {
                    if (! is_array($field)) {
                        continue;
                    }

                    $key = (string) ($field['key'] ?? '');

                    if ($key !== '' && in_array($key, $keys, true)) {
                        $validator->errors()->add(
                            "dynamicFormSchema.{$i}.key",
                            "The field key \"{$key}\" is used more than once. Each field needs its own key.",
                        );
                    }

                    $keys[] = $key;
                }

                foreach ($schema as $i => $field) {
                    if (! is_array($field)) {
                        continue;
                    }

                    $dependsOn = (string) ($field['dependsOn'] ?? '');
                    $source = (string) ($field['dataSource'] ?? '');

                    if ($dependsOn !== '' && ! in_array($dependsOn, $keys, true)) {
                        $validator->errors()->add(
                            "dynamicFormSchema.{$i}.dependsOn",
                            "This field depends on \"{$dependsOn}\", which is not one of the fields on this form.",
                        );
                    }

                    if ($dependsOn !== '' && $source !== '' && MasterDataRegistry::parentColumn($source) === null) {
                        $validator->errors()->add(
                            "dynamicFormSchema.{$i}.dependsOn",
                            'Only a list that belongs to a parent can depend on another field.',
                        );
                    }

                    /* Two fields writing to one column: the second wins, and
                       the first is displayed, filled in and thrown away. */
                    $target = $field['storesIn'] ?? null;

                    if (is_string($target) && $target !== '') {
                        foreach ($schema as $j => $other) {
                            if ($j >= $i || ! is_array($other)) {
                                continue;
                            }

                            if (($other['storesIn'] ?? null) === $target) {
                                $validator->errors()->add(
                                    "dynamicFormSchema.{$i}.storesIn",
                                    'Another field on this form already stores its answer there.',
                                );
                                break;
                            }
                        }
                    }

                    $condition = $field['requiredWhen'] ?? null;

                    if (is_array($condition)) {
                        $on = (string) ($condition['field'] ?? '');

                        if ($on !== '' && ! in_array($on, $keys, true)) {
                            $validator->errors()->add(
                                "dynamicFormSchema.{$i}.requiredWhen.field",
                                "This rule depends on \"{$on}\", which is not one of the fields on this form.",
                            );
                        }
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dynamicFormSchema.*.key.regex' => 'Field keys may only contain lowercase letters, numbers and underscores.',
            'dynamicFormSchema.*.dataSource.in' => 'That is not a list this application manages.',
            'dynamicFormSchema.*.storesIn.in' => 'That is not a registration field a customer type may write to.',
        ];
    }
}
