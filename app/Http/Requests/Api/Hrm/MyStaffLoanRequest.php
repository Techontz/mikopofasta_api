<?php

namespace App\Http\Requests\Api\Hrm;

/**
 * §29 staff self-service loan request: the applicant is always the signed-in employee (branch and employee are never taken
 * from the client).
 */
class MyStaffLoanRequest extends StaffLoanRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['blanch_id' => $this->user()->branch_id, 'empl_id' => $this->user()->id]);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['blanch_id.required' => 'Your staff account has no branch; ask HR to enter the request.'];
    }
}
