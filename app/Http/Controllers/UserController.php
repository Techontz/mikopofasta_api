<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\Actions\CreateUserAction;
use App\Domain\Auth\Actions\DeleteUserAction;
use App\Domain\Auth\Actions\SetUserStatusAction;
use App\Domain\Auth\Actions\UpdateUserAction;
use App\Domain\Auth\DTOs\CreateUserData;
use App\Domain\Auth\DTOs\UpdateUserData;
use App\Domain\Auth\Enums\UserStatus;
use App\Http\Requests\Users\IndexUserRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Requests\Users\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * User administration — the standard CRUD pattern from spec §15, plus the
 * status toggle the frontend's users table calls.
 *
 * Controllers stay thin: validate (Form Request), authorize (Policy), delegate
 * (Action), shape (Resource). No business rule lives here.
 */
final class UserController extends Controller
{
    /**
     * GET /api/v1/users
     */
    public function index(IndexUserRequest $request): JsonResponse
    {
        $this->authorize('viewAny', User::class);

        $filters = $request->validated();

        $query = User::query()
            ->with('role')
            ->when(
                isset($filters['search']),
                fn ($q) => $q->where(function ($q) use ($filters): void {
                    $term = '%'.$filters['search'].'%';
                    $q->where('name', 'like', $term)
                        ->orWhere('phone', 'like', $term)
                        ->orWhere('email', 'like', $term);
                }),
            )
            ->when(isset($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', $filters['branch_id']))
            ->when(
                isset($filters['role']),
                fn ($q) => $q->whereHas('role', fn ($r) => $r->where('name', $filters['role'])),
            )
            // Read through the request rather than the validated array: a
            // query string carries "1"/"true" as a string, which no strict
            // comparison against `true` would ever match.
            ->when($request->boolean('include_deleted'), fn ($q) => $q->withTrashed())
            ->orderBy('name');

        return ApiResponse::paginated(
            $query->paginate(ApiResponse::perPage($request->query('per_page')))->withQueryString(),
            UserResource::class,
        );
    }

    /**
     * POST /api/v1/users
     */
    public function store(StoreUserRequest $request, CreateUserAction $action): JsonResponse
    {
        $this->authorize('create', User::class);

        $user = $action->handle(
            CreateUserData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new UserResource($user), status: Response::HTTP_CREATED);
    }

    /**
     * GET /api/v1/users/{user}
     */
    public function show(Request $request, User $user): JsonResponse
    {
        $this->authorize('view', $user);

        return ApiResponse::data(new UserResource($user->load('role')));
    }

    /**
     * PUT /api/v1/users/{user}
     */
    public function update(UpdateUserRequest $request, User $user, UpdateUserAction $action): JsonResponse
    {
        $this->authorize('update', $user);

        $updated = $action->handle(
            $user,
            UpdateUserData::fromArray($request->validated()),
            $this->actor($request),
        );

        return ApiResponse::data(new UserResource($updated));
    }

    /**
     * PATCH /api/v1/users/{user}/status
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user, SetUserStatusAction $action): JsonResponse
    {
        $this->authorize('updateStatus', $user);

        $updated = $action->handle(
            $user,
            UserStatus::from((string) $request->validated('status')),
            $this->actor($request),
        );

        return ApiResponse::data(new UserResource($updated));
    }

    /**
     * DELETE /api/v1/users/{user} — soft delete.
     */
    public function destroy(Request $request, User $user, DeleteUserAction $action): JsonResponse
    {
        $this->authorize('delete', $user);

        $action->handle($user, $this->actor($request));

        return ApiResponse::data(['message' => 'User deleted.']);
    }
}
