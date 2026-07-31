<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Enums\ErrorCode;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every uncaught exception as the spec §1 error envelope.
 *
 * Registered once in bootstrap/app.php, so no controller ever has to
 * hand-build an error response — and a new endpoint cannot accidentally leak a
 * differently-shaped error body to the frontend.
 */
final class ApiExceptionRenderer
{
    public function __invoke(Throwable $e, Request $request): ?JsonResponse
    {
        // Non-API traffic (there is almost none — this service is API-only)
        // keeps the framework's default rendering.
        if (! $request->expectsJson()) {
            return null;
        }

        return match (true) {
            $e instanceof DomainException => ApiResponse::error(
                $e->getMessage(),
                $e->errorCode,
                $e->status,
                $e->errors,
            ),

            $e instanceof ValidationException => ApiResponse::error(
                'The given data was invalid.',
                ErrorCode::ValidationFailed,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $e->errors(),
            ),

            $e instanceof AuthenticationException => ApiResponse::error(
                'Unauthenticated.',
                ErrorCode::Unauthenticated,
                Response::HTTP_UNAUTHORIZED,
            ),

            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => ApiResponse::error(
                $this->authorizationMessage($e),
                ErrorCode::Forbidden,
                Response::HTTP_FORBIDDEN,
            ),

            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => ApiResponse::error(
                'That record could not be found.',
                ErrorCode::ResourceNotFound,
                Response::HTTP_NOT_FOUND,
            ),

            // Retry-After / X-RateLimit-* are carried over from the throttle
            // middleware so a client can back off intelligently rather than
            // guessing.
            $e instanceof ThrottleRequestsException => ApiResponse::error(
                'Too many attempts. Please slow down and try again shortly.',
                ErrorCode::TooManyRequests,
                Response::HTTP_TOO_MANY_REQUESTS,
            )->withHeaders($e->getHeaders()),

            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                'That method is not supported for this endpoint.',
                ErrorCode::MethodNotAllowed,
                Response::HTTP_METHOD_NOT_ALLOWED,
            ),

            default => null,
        };
    }

    /**
     * Laravel's default authorization message is the unhelpful "This action is
     * unauthorized." Where a policy supplied its own reason, prefer that.
     */
    private function authorizationMessage(Throwable $e): string
    {
        $message = $e->getMessage();

        if ($message === '' || $message === 'This action is unauthorized.') {
            return "You don't have permission to do that.";
        }

        return $message;
    }
}
