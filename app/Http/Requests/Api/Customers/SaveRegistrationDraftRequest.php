<?php

namespace App\Http\Requests\Api\Customers;

use App\Models\Branch;
use App\Models\CustomerRegistrationDraft;
use App\Models\Employee;
use App\Services\AccessControl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * POST /customer-drafts (CUSTOMER_MODULE_IMPLEMENTATION.md §3.2, CUSTOMER_MODULE_SPEC.md §8.2).
 * Ownership (403) and submitted (409) checks run before validation.
 */
class SaveRegistrationDraftRequest extends FormRequest
{
    private ?CustomerRegistrationDraft $draft = null;

    public function authorize(): bool
    {
        abort_unless(Gate::allows('customers.manage'), 403, 'You do not have permission to perform this action.');

        /** @var Employee $actor */
        $actor = $this->user();

        if (filled($this->input('id'))) {
            $this->draft = CustomerRegistrationDraft::query()->where('company_id', $actor->company_id)->find($this->input('id'));
            abort_if($this->draft === null, 404, 'Draft not found.');
            abort_unless((int) $this->draft->created_by === (int) $actor->id, 403, 'This draft belongs to another officer.');
            abort_if($this->draft->submitted_at !== null, 409, 'This registration has already been submitted.');
        }

        $branchId = $this->input('branchId');
        if (is_numeric($branchId) && Branch::whereKey((int) $branchId)->where('company_id', $actor->company_id)->exists()) {
            $visible = app(AccessControl::class)->branchIds($actor);
            abort_unless($visible === null || in_array((int) $branchId, $visible, true), 403, 'You do not have access to this branch.');
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'integer'],
            'branchId' => ['required', 'integer', Rule::exists('branches', 'id')->where('company_id', $this->user()->company_id)],
            'label' => ['required', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:20'],
            'step' => ['nullable', 'integer', 'min:0', 'max:20'],
            'payload' => ['present', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'branchId.required' => 'Select a branch before saving a draft.',
        ];
    }

    public function draft(): ?CustomerRegistrationDraft
    {
        return $this->draft;
    }
}
