<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Auth\Actions\ResetPasswordAction;
use App\Domain\Auth\Actions\SendPasswordResetLinkAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Forgotten-password flow.
 *
 * Delivery is by email. Note that authentication itself is by phone and
 * `users.email` is nullable (spec §2.1), so an account provisioned without an
 * email address cannot self-serve a reset — see README.md, Phase 2 notes.
 */
final class PasswordResetController extends Controller
{
    /**
     * POST /api/v1/auth/forgot-password
     *
     * Always reports success. Confirming whether an address is registered
     * would make this endpoint a user-enumeration oracle, and the rate limiter
     * alone would not prevent that.
     */
    public function sendResetLink(ForgotPasswordRequest $request, SendPasswordResetLinkAction $action): JsonResponse
    {
        $action->handle((string) $request->validated('email'));

        return ApiResponse::data([
            'message' => 'If that email address matches an account, a reset link is on its way.',
        ]);
    }

    /**
     * POST /api/v1/auth/reset-password
     */
    public function reset(ResetPasswordRequest $request, ResetPasswordAction $action): JsonResponse
    {
        $action->handle(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return ApiResponse::data([
            'message' => 'Password reset. You can now sign in with your new password.',
        ]);
    }
}
