<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /customers/{customer}/documents.
 *
 * `documentType` is free text because the frontend's upload panel lets staff
 * type one (matching the category's `required_documents` entries, e.g.
 * "salary_slip"), and categories are admin-editable — a fixed list here would
 * go stale the moment someone adds a category.
 */
final class UploadDocumentRequest extends FormRequest
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
            'documentType' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9 _-]+$/'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documentType.required' => 'Document type is required.',
            'documentType.regex' => 'Document type may only contain letters, numbers, spaces, hyphens and underscores.',
            'file.max' => 'The document must not be larger than 10 MB.',
            'file.mimes' => 'Documents must be a PDF or an image.',
        ];
    }
}
