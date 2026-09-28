<?php

namespace App\Http\Requests\Api\Shares;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Base of the Shares form requests: the permission is checked before any validation runs (403 before 422), and
 * amounts typed with thousands separators ("50,000,000") are normalised.
 */
abstract class SharesRequest extends FormRequest
{
    public const DOCUMENT_MIMES = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    public const DOCUMENT_MAX_KB = 5120;

    /**
     * Permission required to submit the request.
     */
    abstract protected function permission(): string;

    /**
     * Fields holding amounts or share counts that may be typed with separators.
     *
     * @return list<string>
     */
    protected function numericFields(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return Gate::allows($this->permission());
    }

    protected function failedAuthorization(): void
    {
        throw new HttpException(403, 'You do not have permission to perform this action.');
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach ($this->numericFields() as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = str_replace([',', ' '], '', $this->input($field));
            }
        }
        foreach (['idempotency_key', 'notes', 'reason'] as $field) {
            if ($this->input($field) === '') {
                $clean[$field] = null;
            }
        }

        $this->merge($clean);
    }

    protected function shareHolderRule(): Exists
    {
        return Rule::exists('share_holders', 'id')->where('company_id', $this->user()->company_id);
    }
}
