<?php

namespace Tests\Concerns;

use App\Models\Loan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * The credit officer cannot approve until the customer's signed loan agreement is uploaded (LoanWorkflow::approveCredit),
 * so tests that drive a loan past credit review upload one first as the signed-in employee.
 */
trait UploadsLoanAgreement
{
    protected function uploadAgreement(Loan $loan): TestResponse
    {
        Storage::fake('public');

        return $this->postJson(route('api.v1.loans.agreement', $loan), [
            'attach' => UploadedFile::fake()->create('mkataba.pdf', 20, 'application/pdf'),
        ]);
    }
}
