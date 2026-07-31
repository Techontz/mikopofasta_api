<?php

declare(strict_types=1);

namespace App\Http\Controllers\Treasury\Concerns;

use App\Domain\Treasury\Policies\CapitalPolicy;
use App\Models\FloatTransfer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CapitalPolicy covers the whole module and is not bound to a single model, so
 * it is called directly rather than through $this->authorize(). Shared by the
 * Capital controllers so the check reads the same in all of them.
 */
trait AuthorizesCapital
{
    private function authorizeCapital(string $ability, Request $request): void
    {
        abort_unless(app(CapitalPolicy::class)->{$ability}($this->actor($request)), Response::HTTP_FORBIDDEN);
    }

    /** §14: the requester of a transfer may not be the one who decides it. */
    private function authorizeDecision(Request $request, FloatTransfer $transfer): void
    {
        abort_unless(
            app(CapitalPolicy::class)->decide($this->actor($request), $transfer),
            Response::HTTP_FORBIDDEN,
        );
    }
}
