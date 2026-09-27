<?php

namespace App\Http\Requests\Api\Customers;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * POST /customers/{customer}/documents (CUSTOMER_MODULE_IMPLEMENTATION.md §5.6).
 */
class StoreCustomerDocumentRequest extends CustomerScopedRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'documentType' => ['required', 'string', 'max:60', Rule::exists('document_types', 'code')->whereNull('deleted_at')],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentType.exists' => 'That document type is not configured. Choose one from the list.',
            'file.mimes' => 'Documents must be a PDF or an image.',
            'file.max' => 'The document must not be larger than 10 MB.',
        ];
    }
}
