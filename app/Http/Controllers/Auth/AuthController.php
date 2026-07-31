<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Actions\ChangePasswordAction;
use App\Domain\Auth\Actions\LoginAction;
use App\Domain\Auth\Actions\LogoutAction;
use App\Domain\Auth\DTOs\LoginData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthenticatedUserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session lifecycle: login, logout, current user, change password.
 *
 * Consumed by the Next.js BFF (frontend spec §2), never by a browser directly
 * — the bearer token must not reach client-side JavaScript.
 */
final class AuthController extends Controller
{
    /**
     * POST /api/v1/auth/login
     *
     * Returns the token plus the profile the BFF encrypts into its session
     * cookie: role, resolved permissions and branch/zone/region scope.
     */
    public function login(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $result = $action->handle(LoginData::fromArray($request->validated()));

        return ApiResponse::data([
            'token' => $result['token'],
            'tokenType' => 'Bearer',
            'user' => new AuthenticatedUserResource($result['user']),
        ]);
    }

    /**
     * POST /api/v1/auth/logout — revokes the current token only.
     */
    public function logout(Request $request, LogoutAction $action): JsonResponse
    {
        $action->handle($this->user($request));

        return ApiResponse::data(['message' => 'Signed out.']);
    }

    /**
     * GET /api/v1/auth/me
     *
     * Lets the BFF re-resolve permissions without a fresh login, which is how
     * frontend spec §2 step 6 bounds permission drift after an administrator
     * edits the matrix mid-session.
     */
    public function me(Request $request): JsonResponse
    {
        return ApiResponse::data(
            new AuthenticatedUserResource($this->user($request)->load('role')),
        );
    }

    /**
     * POST /api/v1/auth/change-password
     *
     * Returns a fresh token: changing the password revokes every existing one,
     * including the caller's own.
     */
    public function changePassword(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        $token = $action->handle(
            $this->user($request),
            (string) $request->validated('current_password'),
            (string) $request->validated('password'),
        );

        return ApiResponse::data([
            'token' => $token,
            'tokenType' => 'Bearer',
            'message' => 'Password updated. Other sessions have been signed out.',
        ]);
    }

    /**
     * These routes sit behind `auth:sanctum`, so a user is always present.
     * Resolving it through a typed helper keeps PHPStan honest without
     * scattering assertions through the controller.
     */
    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
