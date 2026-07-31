<?php

declare(strict_types=1);

namespace App\Domain\Expenses\Actions;

use App\Enums\AuditAction;
use App\Models\ExpenseRequest;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Saves the decision comment — the pencil action on every claim row.
 *
 * Deliberately available after a decision as well as before it. The legacy
 * screen offers the comment on approved rows too, and it is the only place the
 * reason for a decision is written down; locking it at approval would mean the
 * reason had to be right first time or be lost. Nothing financial changes, and
 * the before/after pair is audited, so the earlier text is never destroyed —
 * it is superseded on the record.
 */
final class CommentOnExpenseRequestAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ExpenseRequest $request, ?string $comment, User $actor): ExpenseRequest
    {
        $before = $request->comment;

        $request->update(['comment' => $comment]);

        $this->audit->log(
            AuditAction::ExpenseCommented,
            $request,
            before: ['comment' => $before],
            after: ['comment' => $comment],
            actor: $actor,
        );

        return $request->load(ExpenseRequest::LIST_RELATIONS);
    }
}
