<?php

declare(strict_types=1);

namespace App\Http\Requests\Customers;

use App\Domain\Customers\Enums\Gender;
use App\Domain\Customers\Enums\GuarantorRelationship;
use App\Domain\Customers\Enums\MaritalStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Mirrors the frontend's CreateGuarantorInputSchema.
 *
 * Accepts multipart as well as JSON: the passport is a file, so the create form
 * posts `FormData`. Every scalar rule below is unchanged by that — Laravel
 * validates a multipart field exactly as it validates a JSON one.
 */
final class StoreGuarantorRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'min:9', 'max:20'],
            'nidaNumber' => ['nullable', 'string', 'max:30'],
            'relationship' => ['required', 'string', Rule::in(GuarantorRelationship::values())],
            'address' => ['nullable', 'string', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:150'],

            /*
             * The application's own enums, not a list written out here — the
             * same two `customers` validates for the same two facts. Nullable
             * because the guarantors already on the books have neither, and a
             * required rule would make every one of them un-editable.
             */
            'gender' => ['nullable', 'string', Rule::in(Gender::values())],
            'maritalStatus' => ['nullable', 'string', Rule::in(MaritalStatus::values())],

            /*
             * The passport photograph. Same rule as UploadDocumentRequest,
             * because it is the same kind of file going to the same private
             * disk — a passport may be photographed or scanned to PDF, so both
             * are accepted, and 10 MB is the limit KYC uploads already carry.
             *
             * Optional: the branch cannot always photograph a guarantor on the
             * spot, and refusing the whole application over it would push the
             * officer into inventing one.
             */
            'passport' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],

            /*
             * An import: copy this existing guarantor's passport onto the new
             * record. The browser holds no file to re-upload, so the copy is
             * made server-side on the private disk.
             *
             * Only the FILE travels. Every other field is validated above from
             * what the client sent, so an import cannot smuggle a value past
             * the same rules a typed-in guarantor obeys. The source is
             * branch-checked in the controller — an id alone must not reach
             * across branches.
             */
            'copyPassportFromGuarantorId' => ['nullable', 'integer', 'exists:guarantors,id'],
        ];
    }
}
