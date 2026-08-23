<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Customers\DTOs\KycRequirement;
use App\Domain\Customers\Enums\CustomerApprovalStatus;
use App\Domain\Customers\Enums\RegistrationStage;
use App\Models\Customer;

/**
 * Where a customer stands in the registration workflow, and what is left.
 *
 *     Draft → Information incomplete → Awaiting face verification
 *           → KYC complete → Eligible for loan
 *
 * Read by the customer profile, by the customer list and by the wizard when it
 * reopens a saved registration, so all three describe the same customer the
 * same way. Nothing here is stored — see RegistrationStage for why.
 *
 * The distinction that earns this class its existence is between "the face
 * scan is outstanding" and "something else is outstanding". Both used to
 * present as `kyc_status: incomplete`, which told an officer holding a
 * finished file nothing about whether they had forgotten a field or simply had
 * not reached the camera yet.
 */
final class RegistrationProgress
{
    public function __construct(private readonly KycEvaluator $kyc) {}

    public function stageFor(Customer $customer): RegistrationStage
    {
        $requirements = $this->kyc->requirements($customer);

        $outstanding = array_values(array_filter(
            $requirements,
            static fn (KycRequirement $r): bool => $r->outstanding(),
        ));

        if ($outstanding !== []) {
            /*
             * The face scan alone is its own stage. Only when it is the ONLY
             * thing left — a file that is otherwise finished — because
             * "awaiting face verification" on a record that is also missing
             * its address would send somebody to the camera to discover the
             * save still fails.
             */
            $onlyFace = count($outstanding) === 1 && $outstanding[0]->key === 'faceVerified';

            return $onlyFace
                ? RegistrationStage::AwaitingFaceVerification
                : RegistrationStage::InformationIncomplete;
        }

        return $customer->isLoanEligible()
            ? RegistrationStage::LoanEligible
            : RegistrationStage::NotEligible;
    }

    /**
     * The stage, why it is that stage, and what to do about it.
     *
     * One shape for every consumer. `nextAction` is a sentence rather than a
     * code because the caller renders it verbatim: three screens showing three
     * paraphrases of the same instruction is how a workflow stops being one
     * workflow.
     *
     * @return array{
     *     stage: string,
     *     label: string,
     *     outstanding: list<string>,
     *     nextAction: string,
     *     isLoanEligible: bool
     * }
     */
    public function describe(Customer $customer): array
    {
        $stage = $this->stageFor($customer);

        return [
            'stage' => $stage->value,
            'label' => $stage->label(),
            'outstanding' => $this->kyc->outstanding($customer),
            'nextAction' => $this->nextAction($customer, $stage),
            'isLoanEligible' => $customer->isLoanEligible(),
        ];
    }

    /**
     * Every stage has one. There is deliberately no null case: a status the
     * officer cannot act on is a status they will raise a ticket about.
     */
    private function nextAction(Customer $customer, RegistrationStage $stage): string
    {
        return match ($stage) {
            RegistrationStage::Draft => 'Resume the registration and complete the remaining steps.',
            RegistrationStage::InformationIncomplete => 'Edit the customer and complete the outstanding items.',
            RegistrationStage::AwaitingFaceVerification => 'Run Face Verification on this customer. Any signed-in device with a camera will do.',
            RegistrationStage::LoanEligible => 'Start a loan application.',

            /*
             * Not eligible has several distinct causes and one useless
             * message. Naming the actual one is the difference between an
             * officer resolving it and an officer raising a ticket.
             */
            RegistrationStage::NotEligible => match (true) {
                $customer->approval_status === CustomerApprovalStatus::Pending => 'This category requires extra approval. A supervisor must approve the registration.',
                $customer->approval_status === CustomerApprovalStatus::Rejected => 'The registration was rejected. Review the rejection reason before re-submitting.',
                $customer->isFrozen() => 'The account is frozen. It must be unfrozen before any new loan.',
                default => 'The account is not active. Reactivate it before applying for a loan.',
            },
        };
    }
}
