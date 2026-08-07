<?php

declare(strict_types=1);

namespace App\Domain\Loans\Exceptions;

use App\Domain\Loans\Enums\LoanStatus;
use App\Enums\ErrorCode;
use App\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Something is wrong with a decision taken on the approval chain.
 *
 * Kept apart from LoanStateException because these are not all state problems:
 * a missing permission is a 403 and a misconfigured stage is an administrator's
 * mistake, and collapsing them into one conflict code would tell the caller the
 * loan was in the wrong state when it was not.
 */
final class LoanApprovalException extends DomainException
{
    private function __construct(string $message, ErrorCode $code, int $status)
    {
        parent::__construct($message, $code, $status);
    }

    public static function notAwaitingApproval(LoanStatus $status): self
    {
        return new self(
            sprintf('This loan is %s, so there is no approval decision to take on it.', strtolower($status->label())),
            ErrorCode::InvalidLoanState,
            Response::HTTP_CONFLICT,
        );
    }

    public static function notPermittedAtStage(string $stage, string $permission): self
    {
        return new self(
            sprintf('Deciding at the %s stage requires the %s permission.', $stage, $permission),
            ErrorCode::Forbidden,
            Response::HTTP_FORBIDDEN,
        );
    }

    public static function selfApproval(string $stage): self
    {
        return new self(
            sprintf("You submitted this application, so you can't decide it at the %s stage.", $stage),
            ErrorCode::InvalidLoanState,
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * The chain has no active stages at all.
     *
     * A configuration state, not a loan state: an application submitted into an
     * empty chain would sit in a status nobody can act on.
     */
    public static function noChainConfigured(): self
    {
        return new self(
            'No approval stages are active, so an application has nowhere to go. Configure the approval chain first.',
            ErrorCode::InvalidLoanState,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function stageMisconfigured(string $stage, string $permission): self
    {
        return new self(
            sprintf(
                'The %s stage requires a permission that does not exist [%s]. No decision can be authorised until it is corrected.',
                $stage,
                $permission,
            ),
            ErrorCode::InvalidLoanState,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * The branch has no code, so no customer reference can be built for it.
     *
     * An administrator's mistake rather than a state problem, and named as such:
     * the officer reading this cannot fix it, but they can say precisely what is
     * wrong to somebody who can. Every branch is given a code by migration, so
     * this means one was cleared afterwards.
     */
    public static function branchHasNoCode(string $branch): self
    {
        return new self(
            sprintf(
                'Branch "%s" has no branch code, so a customer payment reference cannot be issued. Set the branch code before approving this loan.',
                $branch,
            ),
            ErrorCode::InvalidLoanState,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function notOnHold(): self
    {
        return new self(
            'This loan is not on hold, so there is nothing to release.',
            ErrorCode::InvalidLoanState,
            Response::HTTP_CONFLICT,
        );
    }

    public static function notReturned(): self
    {
        return new self(
            'This loan has not been returned for modification, so there is nothing to resubmit.',
            ErrorCode::InvalidLoanState,
            Response::HTTP_CONFLICT,
        );
    }

    /**
     * A held loan whose resume point was lost.
     *
     * Should be unreachable — the hold writes it in the same transaction as the
     * status change — but releasing into a guess would put the loan at a stage
     * nobody chose, so it refuses instead.
     */
    public static function holdResumeUnknown(): self
    {
        return new self(
            'This loan is on hold but has no recorded stage to return to. It must be resolved manually.',
            ErrorCode::InvalidLoanState,
            Response::HTTP_CONFLICT,
        );
    }
}
