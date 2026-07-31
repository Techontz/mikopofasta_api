<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PUT /customers/{customer}/category — spec §15.1. */
final class AssignCategoryRequest extends FormRequest
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
        return [
            'customerCategoryId' => [
                'required', 'integer',
                Rule::exists('customer_categories', 'id')->whereNull('deleted_at'),
            ],
            'dynamicFormData' => ['present', 'array'],
        ];
    }
}
