<?php

declare(strict_types=1);

namespace App\Http\Requests\Loans;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The disbursement callback payload — §15.2.
 *
 * Mirrors the frontend's `settleDisbursement(loanId, success, failureReason?)`.
 * `batchReference` is required only on the webhook route, where the provider
 * knows the reference and nothing else about our data model; the authenticated
 * route identifies the loan in the URL and settles its in-flight batch.
 */
final class SettleDisbursementRequest extends FormRequest
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
            'success' => ['required', 'boolean'],
            'failureReason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'batchReference' => [
                $this->routeIs('webhooks.*') ? 'required' : 'sometimes',
                'string',
                'max:40',
            ],
        ];
    }

    /**
     * §15.2's payload is snake_case; the frontend's is camelCase. Both are
     * accepted so an integration written against either spelling works — the
     * same accommodation InboundPaymentRequest makes.
     */
    protected function prepareForValidation(): void
    {
        foreach (['batch_reference' => 'batchReference', 'failure_reason' => 'failureReason'] as $snake => $camel) {
            if (! $this->has($camel) && $this->has($snake)) {
                $this->merge([$camel => $this->input($snake)]);
            }
        }
    }
}
