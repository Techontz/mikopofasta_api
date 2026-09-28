<?php

namespace App\Services;

use App\Enums\Duration;
use App\Enums\LoanStatus;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Loan;
use Illuminate\Validation\ValidationException;

/**
 * The loan agreement (mkataba wa mkopo) the system generates once the branch manager has approved: everything the
 * system already knows is printed, the customer fills what is missing and signs, and the signed copy is uploaded
 * (LoanWorkflow::uploadAgreement) before the credit officer can approve.
 *
 * Built from the loan as it stands, so a reprint after a re-approval shows the current terms. Missing values are
 * null, never invented: the printed page leaves a line for the customer to fill in.
 */
class LoanAgreement
{
    public function __construct(private readonly LoanWorkflow $workflow) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Loan $loan): array
    {
        if (! in_array($loan->status, LoanStatus::agreementAvailable(), true)) {
            throw ValidationException::withMessages(['loan' => LoanWorkflow::AGREEMENT_NOT_READY]);
        }

        $loan->loadMissing([
            'company', 'branch', 'category', 'employee', 'schedules', 'agreementUploader', 'topupOf',
            'customer.region', 'customer.idType', 'customer.maritalStatusRecord', 'customer.nextOfKins',
            'guarantors.region', 'collaterals',
        ]);
        $customer = $loan->customer;
        $company = $loan->company;
        $duration = $loan->duration ?? Duration::Monthly;

        return [
            'company' => [
                'name' => $company->name,
                'registration_number' => $company->registration_number ?: null,
                'address' => $company->address ?: null,
                'phone' => $company->phone ?: null,
                'email' => $company->email ?: null,
                'logo_url' => $company->logo ? asset('storage/'.$company->logo) : null,
            ],
            'branch' => ['name' => $loan->branch?->name, 'phone' => $loan->branch?->phone],
            'agreement' => [
                'number' => 'MKT-'.$loan->loan_number,
                'loan_number' => $loan->loan_number,
                'reference_number' => $loan->reference_number,
                'approved_at' => $loan->approved_at?->toDateString(),
                'approved_by' => $this->managerWhoApproved($loan),
                'loan_officer' => $loan->employee?->full_name,
                'generated_at' => now()->toDateTimeString(),
                'uploaded_file' => $loan->agreement_file ? asset('storage/'.$loan->agreement_file) : null,
                'uploaded_at' => $loan->agreement_uploaded_at?->toDateTimeString(),
                'uploaded_by' => $loan->agreementUploader?->full_name,
            ],
            'borrower' => $this->borrower($customer),
            'loan' => [
                'category' => $loan->category?->name,
                'amount' => (float) $loan->amount_approved,
                'interest_rate' => (float) $loan->interest_rate,
                'interest_amount' => (float) $loan->interest_amount,
                'total_payable' => (float) $loan->total_payable,
                'loan_fee' => (float) $loan->loan_fee,
                'fee_deducted' => (bool) $loan->fee_deduct,
                'insurance' => (float) $loan->insurance,
                'net_disbursement' => $this->workflow->netDisbursement($loan),
                'topup_of' => $loan->topupOf?->loan_number,
                'frequency' => $duration->value,
                'instalments' => (int) $loan->sessions,
                'instalment_amount' => (float) $loan->restoration,
                'purpose' => $loan->reason,
                'disbursed_on' => $loan->withdrawn_at?->toDateString(),
                'end_date' => $loan->end_date?->toDateString(),
                // Early full settlement starts the category's re-borrowing freeze (Loan Freeze Time rule).
                'freeze_days' => (int) ($loan->category?->freeze_time_days ?? 0),
                'penalty' => $loan->category?->has_penalty ? [
                    'type' => $company->penalty_type === 'percentage' ? 'percentage' : 'money',
                    'value' => (float) $company->penalty_value,
                ] : null,
            ],
            // Real due dates exist once the money is sent; before that the page prints the amounts with blank dates.
            'schedule' => $loan->schedules->isNotEmpty()
                ? $loan->schedules->values()->map(fn ($row, int $index): array => ['number' => $index + 1, 'due_date' => $row->due_date?->toDateString(), 'amount' => (float) $row->amount])->all()
                : collect(range(1, max(1, (int) $loan->sessions)))->map(fn (int $number): array => ['number' => $number, 'due_date' => null, 'amount' => (float) $loan->restoration])->all(),
            'guarantors' => $loan->guarantors->map(fn ($guarantor): array => [
                'full_name' => trim("{$guarantor->first_name} {$guarantor->middle_name} {$guarantor->last_name}") ?: $guarantor->name,
                'phone' => $guarantor->phone,
                'id_number' => $guarantor->nida_number ?: $guarantor->id_number,
                'relationship' => $guarantor->relationship,
                'gender' => $guarantor->gender,
                'marital_status' => $guarantor->marital_status,
                'photo_url' => $guarantor->photo ? asset('storage/'.$guarantor->photo) : null,
                'occupation' => $guarantor->occupation,
                'address' => collect([$guarantor->street, $guarantor->ward, $guarantor->district, $guarantor->region?->name])->filter()->implode(', ') ?: $guarantor->address,
            ])->values()->all(),
            'collaterals' => $loan->collaterals->map(fn ($collateral): array => [
                'name' => $collateral->name,
                'type' => $collateral->type,
                'location' => $collateral->location,
                'value' => (float) $collateral->value,
            ])->values()->all(),
            'next_of_kin' => $customer->nextOfKins->map(fn ($kin): array => [
                'full_name' => trim("{$kin->first_name} {$kin->middle_name} {$kin->last_name}") ?: $kin->name,
                'phone' => $kin->phone,
                'relationship' => $kin->relationship,
                'address' => $kin->address,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function borrower(Customer $customer): array
    {
        $idNumber = $customer->nida_number ?: $customer->national_id_number ?: $customer->id_number;

        return [
            'full_name' => $customer->full_name,
            'customer_code' => $customer->customer_code,
            // The face-scan capture streams through the authorised API route; the legacy passport photo is public.
            'photo_url' => $customer->photo_path
                ? "customers/{$customer->id}/photo"
                : ($customer->passport_photo ? asset('storage/'.$customer->passport_photo) : null),
            'id_type' => $customer->idType?->name ?? ($customer->nida_number ? 'NIDA' : null),
            'id_number' => $idNumber ?: null,
            'date_of_birth' => $customer->date_of_birth?->toDateString(),
            'gender' => $customer->gender,
            'marital_status' => $customer->maritalStatusRecord?->name ?? $customer->marital_status,
            'phone' => $customer->phone,
            'alternative_phone' => $customer->alternative_phone,
            'email' => $customer->email,
            'region' => $customer->region?->name,
            // `district` is both a legacy text column and the district_id relation; the column wins when filled.
            'district' => $customer->getAttribute('district') ?: $customer->district()->value('name'),
            'ward' => $customer->ward_name ?? $customer->ward,
            'street' => $customer->street_name ?? $customer->street,
            'house_number' => $customer->house_number,
            'postal_code' => $customer->postal_code,
            'occupation' => $customer->occupation ?? $customer->business_type,
            'employer' => $customer->employer ?? $customer->place_of_employment,
            'department' => $customer->department,
            'check_number' => $customer->check_number,
            'business_name' => $customer->business_name,
            'business_address' => $customer->business_address ?? $customer->place_of_business,
            'monthly_income' => (float) ($customer->basic_salary ?: $customer->monthly_income),
            'bank_name' => $customer->bank_name,
            'account_number' => $customer->account_number,
            'wallet' => $customer->wallet_number ? trim($customer->mobile_money_provider.' '.$customer->wallet_number) : null,
        ];
    }

    private function managerWhoApproved(Loan $loan): ?string
    {
        return AuditLog::query()
            ->where('auditable_type', $loan->getMorphClass())
            ->where('auditable_id', $loan->id)
            ->where('action', 'MANAGER_APPROVED')
            ->latest('id')
            ->first()?->employee?->full_name;
    }
}
