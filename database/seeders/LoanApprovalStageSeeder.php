<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Loans\Enums\LoanStatus;
use App\Models\LoanApprovalStage;
use Illuminate\Database\Seeder;

/**
 * The approval chain the client specified.
 *
 *     Loan Officer → Branch Manager → Zone Manager → Head Office Credit → Disbursement
 *
 * The Loan Officer is not a stage: they raise the application rather than
 * approving it, and §14 forbids them from doing both. Disbursement is not a
 * stage either — it is Finance executing a decision already taken, which is why
 * the chain ends by handing the loan to `pending_finance`.
 *
 * Sequences are gapped by ten so a tier can be inserted between two existing
 * ones — a regional step, or a second credit review above a threshold —
 * without renumbering anything.
 */
final class LoanApprovalStageSeeder extends Seeder
{
    public function run(): void
    {
        $stages = [
            [
                'name' => 'Branch Manager',
                'code' => 'BRANCH_MANAGER',
                'sequence' => 10,
                'loan_status' => LoanStatus::PendingManagerApproval->value,
                'required_permission' => PermissionName::LoansApprove->value,
                'requires_mandate_before' => false,
                'description' => 'The branch signs off the application it raised. The repayment schedule is generated when this stage clears.',
                'is_active' => true,
            ],
            [
                'name' => 'Zone Manager',
                'code' => 'ZONE_MANAGER',
                'sequence' => 20,
                'loan_status' => LoanStatus::PendingZoneApproval->value,
                'required_permission' => PermissionName::LoansZoneApprove->value,
                'requires_mandate_before' => false,
                'description' => 'Zone oversight of the branch decision, across the branches the zone covers.',
                'is_active' => true,
            ],
            [
                'name' => 'Head Office Credit',
                'code' => 'HEAD_OFFICE_CREDIT',
                'sequence' => 30,
                'loan_status' => LoanStatus::PendingCreditReview->value,
                'required_permission' => PermissionName::LoansCreditReview->value,
                /*
                 * The bank e-mandate must be live before credit reviews the
                 * file — §10's conditional branch, expressed as data so the
                 * workflow does not have to test a status name.
                 */
                'requires_mandate_before' => true,
                'description' => 'Credit review at head office, including telco verification. The last approval before the loan goes to Finance.',
                'is_active' => true,
            ],
        ];

        foreach ($stages as $stage) {
            LoanApprovalStage::query()->updateOrCreate(['code' => $stage['code']], $stage);
        }
    }
}
