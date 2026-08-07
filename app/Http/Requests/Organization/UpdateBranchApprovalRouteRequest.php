<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The routing overrides an administrator is pinning for one branch — D4.
 *
 * Each entry names a stage and says `true` (always include it here) or `false`
 * (never include it here). A stage the caller omits has no override and follows
 * the default rule, which for the zone stage is "include it when the branch
 * belongs to a zone".
 */
final class UpdateBranchApprovalRouteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'overrides' => ['present', 'array'],
            'overrides.*.stageId' => ['required', 'integer', 'exists:loan_approval_stages,id'],
            /*
             * Nullable, and null is meaningful: it is how the screen says
             * "remove the pin and go back to following the default", which is
             * otherwise inexpressible without a second endpoint.
             */
            'overrides.*.required' => ['nullable', 'boolean'],
        ];
    }

    /**
     * The overrides as stage id => required, dropping the nulls.
     *
     * @return array<int, bool>
     */
    public function overrides(): array
    {
        $result = [];

        /** @var array<int, array{stageId: int|string, required?: bool|null}> $rows */
        $rows = $this->validated('overrides') ?? [];

        foreach ($rows as $row) {
            if (! array_key_exists('required', $row) || $row['required'] === null) {
                continue;
            }

            $result[(int) $row['stageId']] = (bool) $row['required'];
        }

        return $result;
    }
}
