<?php

declare(strict_types=1);

namespace App\Http\Controllers\Loans;

use App\Domain\Auth\Enums\PermissionName;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\LoanProduct;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which branches offer a loan product — Administration → Loan Category →
 * Assign Branch.
 *
 * The screen shows two lists: the branches this product is NOT yet offered at,
 * and the ones it is. Assigning and unassigning are single rows in a pivot, so
 * they are their own small endpoints rather than a whole-product save — an
 * administrator ticking a branch should not have to resend the interest rate.
 *
 * EMPTY MEANS EVERY BRANCH, and the index says so, because a screen showing an
 * empty "assigned" list has to explain whether that means nowhere or
 * everywhere.
 *
 * Gated on `admin.org_settings`, like every other loan-product write.
 */
final class LoanProductBranchController extends Controller
{
    /**
     * GET /api/v1/loan-products/{product}/branches
     */
    public function index(Request $request, LoanProduct $product): JsonResponse
    {
        $this->authorizeAdmin($request);

        $assigned = $product->branches()->orderBy('name')->get();
        $assignedIds = $assigned->pluck('id')->all();

        $available = Branch::query()
            ->whereNotIn('id', $assignedIds === [] ? [0] : $assignedIds)
            ->orderBy('name')
            ->get();

        return ApiResponse::data([
            'product' => ['id' => (string) $product->getKey(), 'name' => $product->name],
            'available' => $available->map(fn (Branch $b): array => [
                'id' => (string) $b->getKey(),
                'name' => $b->name,
            ])->all(),
            'assigned' => $assigned->map(fn (Branch $b): array => [
                'id' => (string) $b->getKey(),
                'name' => $b->name,
            ])->all(),
            /* An empty assignment is not a restriction — see the model. */
            'offeredEverywhere' => $assigned->isEmpty(),
        ]);
    }

    /**
     * POST /api/v1/loan-products/{product}/branches
     */
    public function store(Request $request, LoanProduct $product, AuditLogger $audit): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);

        $data = $request->validate([
            'branchId' => ['required', 'integer', 'exists:branches,id'],
        ]);

        /* syncWithoutDetaching, so assigning a branch twice is a no-op rather
           than a unique-constraint error in front of the administrator. */
        $product->branches()->syncWithoutDetaching([$data['branchId']]);

        $audit->log(
            AuditAction::LoanProductUpdated,
            $product,
            after: ['branch_assigned' => $data['branchId']],
            actor: $actor,
        );

        return ApiResponse::data(['message' => 'Branch assigned.'], status: Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/loan-products/{product}/branches/{branch}
     */
    public function destroy(Request $request, LoanProduct $product, Branch $branch, AuditLogger $audit): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);

        $product->branches()->detach($branch->getKey());

        $audit->log(
            AuditAction::LoanProductUpdated,
            $product,
            before: ['branch_unassigned' => $branch->getKey()],
            actor: $actor,
        );

        return ApiResponse::data(['message' => 'Branch removed.']);
    }

    private function authorizeAdmin(Request $request): User
    {
        $actor = $this->actor($request);

        abort_unless($actor->hasPermission(PermissionName::AdminOrgSettings), Response::HTTP_FORBIDDEN);

        return $actor;
    }
}
