<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel 12 ships this base class empty. AuthorizesRequests is added here so
 * every controller can call $this->authorize(); the AuthorizationException it
 * throws is rendered as the spec §1 FORBIDDEN envelope by
 * App\Exceptions\ApiExceptionRenderer.
 */
abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The authenticated user, typed.
     *
     * `$request->user()` is `Authenticatable|null` as far as the type system
     * knows, but every route that calls this sits behind Sanctum — so the null
     * branch is unreachable in practice and aborts rather than being handled.
     *
     * Lives on the base class because twenty-one controllers had an identical
     * private copy; one definition means one place for that reasoning to live.
     */
    protected function actor(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_UNAUTHORIZED);

        return $user;
    }
}
