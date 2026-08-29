<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Loans\Enums\LoanStatus;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\LoanApprovalStageResource;
use App\Models\LoanApprovalStage;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administration → Loan Approval Chain.
 *
 * WHY THIS EXISTS. The chain was already data — `loan_approval_stages`, read
 * by LoanApprovalWorkflow, snapshotted per loan into `loan_approval_routes` —
 * but no endpoint reached it. Branch Manager → Zone → Head Office Credit was
 * configuration nobody could configure, which is the same as hardcoding it
 * with extra steps.
 *
 * WHAT AN ADMINISTRATOR MAY CHANGE. Order, name, description, the permission
 * that may decide it, whether it is active, and the two flags the workflow
 * reads. What they may NOT change is `loan_status`: each stage's status is a
 * value in the `loans.status` enum, and pointing a stage at a status the
 * column cannot hold would strand every loan that reached it. New stages
 * therefore choose from the statuses the schema already defines.
 *
 * LOANS IN FLIGHT ARE NOT AFFECTED. Every loan carries its own route, taken
 * when it was raised (D4), so editing the chain changes what the NEXT
 * application walks and never reroutes one already moving. That is what makes
 * this safe to edit during business hours.
 *
 * DELETION IS REFUSED once a stage has decided anything — the decision history
 * points at it, and an audit trail whose stages have vanished is not one.
 * Deactivating is the ordinary action.
 */
final class LoanApprovalStageController extends Controller
{
    /**
     * The statuses a stage may hold — the ones the workflow understands as
     * "waiting for somebody". Any other loan status describes a loan that is
     * not in the approval chain at all.
     *
     * @var list<string>
     */
    private const array STAGE_STATUSES = [
        'pending_manager_approval',
        'pending_zone_approval',
        'pending_credit_review',
    ];

    /**
     * GET /api/v1/loan-approval-stages
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return ApiResponse::data([
            'stages' => LoanApprovalStageResource::collection(
                LoanApprovalStage::query()->orderBy('sequence')->get(),
            ),
            /* What the UI may offer, from the schema rather than a list the
               frontend keeps. */
            'availableStatuses' => self::STAGE_STATUSES,
        ]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);

        $stage = LoanApprovalStage::query()->create($this->columns($request->validate($this->rules(null))));

        $audit->log(AuditAction::ApprovalStageCreated, $stage, after: $stage->only('code', 'name', 'sequence'), actor: $actor);

        return ApiResponse::data(new LoanApprovalStageResource($stage), status: Response::HTTP_CREATED);
    }

    public function update(Request $request, LoanApprovalStage $stage, AuditLogger $audit): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);

        $before = $stage->only('code', 'name', 'sequence', 'is_active', 'required_permission');
        $stage->update($this->columns($request->validate($this->rules($stage->getKey()))));

        $audit->log(AuditAction::ApprovalStageUpdated, $stage, before: $before, after: $stage->refresh()->only('code', 'name', 'sequence', 'is_active', 'required_permission'), actor: $actor);

        return ApiResponse::data(new LoanApprovalStageResource($stage));
    }

    public function destroy(Request $request, LoanApprovalStage $stage, AuditLogger $audit): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);

        abort_if(
            $stage->decisions()->exists(),
            Response::HTTP_CONFLICT,
            'Loans have been decided at this stage. Deactivate it instead of deleting it — the decision history points here.',
        );

        $audit->log(AuditAction::ApprovalStageDeleted, $stage, before: $stage->only('code', 'name', 'sequence'), actor: $actor);
        $stage->delete();

        return ApiResponse::data(['removed' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignore): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('loan_approval_stages', 'code')->ignore($ignore)->whereNull('deleted_at')],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            /* Order decides the chain. Unique so two stages cannot claim the
               same position and leave the order down to insertion. */
            'sequence' => ['required', 'integer', 'min:1', 'max:9999', Rule::unique('loan_approval_stages', 'sequence')->ignore($ignore)->whereNull('deleted_at')],
            'loanStatus' => ['required', 'string', Rule::in(self::STAGE_STATUSES)],
            /* The permission that may decide here — checked against the
               application's own list, so a typo cannot create a stage nobody
               can ever act on. */
            'requiredPermission' => ['required', 'string', Rule::in(array_column(PermissionName::cases(), 'value'))],
            'requiresMandateBefore' => ['sometimes', 'boolean'],
            'requiresBranchZone' => ['sometimes', 'boolean'],
            'issuesPaymentReference' => ['sometimes', 'boolean'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function columns(array $input): array
    {
        $map = [
            'loanStatus' => 'loan_status',
            'requiredPermission' => 'required_permission',
            'requiresMandateBefore' => 'requires_mandate_before',
            'requiresBranchZone' => 'requires_branch_zone',
            'issuesPaymentReference' => 'issues_payment_reference',
            'isActive' => 'is_active',
        ];

        $columns = [];
        foreach ($input as $key => $value) {
            $columns[$map[$key] ?? $key] = $value;
        }

        if (isset($columns['loan_status'])) {
            $columns['loan_status'] = LoanStatus::from($columns['loan_status']);
        }

        return $columns;
    }

    private function authorizeAdmin(Request $request): User
    {
        $actor = $request->user();

        abort_if($actor === null, Response::HTTP_UNAUTHORIZED);
        abort_unless($actor->hasPermission(PermissionName::AdminOrgSettings), Response::HTTP_FORBIDDEN);

        return $actor;
    }
}
