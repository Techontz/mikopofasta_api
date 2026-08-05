<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Models\MasterData\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /customers/{customer}/documents.
 *
 * `documentType` used to be free text, on the reasoning that categories are
 * admin-editable and a fixed list would go stale. The reasoning was right and
 * the conclusion was wrong: the answer to an editable list is an editable
 * list, not no list. This database already holds a document filed under
 * `HJK`, and a category that requires `salary_slip` is not satisfied by
 * somebody typing `salary slip`.
 *
 * So the type must now be a code from `document_types` — the same
 * admin-managed table the upload dropdown reads and the same codes a
 * category's `required_documents` names. Adding a type stays a data change;
 * inventing one at the keyboard stops being possible.
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
            'documentType' => [
                'required', 'string', 'max:60',
                /* Active entries only: a withdrawn type still renders on the
                   documents that hold it, but must not be filed against
                   anybody new. */
                Rule::exists(DocumentType::class, 'code')->where('is_active', true)->whereNull('deleted_at'),
            ],
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
            'documentType.exists' => 'That document type is not configured. Choose one from the list.',
            'file.max' => 'The document must not be larger than 10 MB.',
            'file.mimes' => 'Documents must be a PDF or an image.',
        ];
    }
}
