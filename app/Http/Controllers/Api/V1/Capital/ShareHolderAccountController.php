<?php

namespace App\Http\Controllers\Api\V1\Capital;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Models\ShareHolder;
use App\Services\Shareholders\ShareholderAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Capital → Shareholders → Login accounts: overview of which shareholders can sign in to the Shareholder Portal, "Generate
 * accounts" for existing shareholders, and per-shareholder login actions (generate, reset temporary password,
 * activate / deactivate). Temporary passwords are returned once and never stored (see {@see EnsureIdempotentRequest::REDACT_ATTRIBUTE}).
 * Capital, shares, dividends and journals are never touched.
 */
class ShareHolderAccountController extends ApiController
{
    public function __construct(private readonly ShareholderAccounts $accounts) {}

    public function index(): JsonResponse
    {
        $this->authorizeAny('users.manage', 'capital.manage');
        $companyId = (int) $this->currentEmployee()->company_id;

        return response()->json(['data' => [
            'totals' => $this->accounts->overview($companyId),
            'share_holders' => $this->accounts->rows($companyId),
        ]]);
    }

    public function generate(Request $request): JsonResponse
    {
        $this->authorizeAny('users.manage', 'capital.manage');
        $companyId = (int) $this->currentEmployee()->company_id;
        $validated = $request->validate([
            'share_holder_ids' => ['nullable', 'array', 'required_without:all'],
            'share_holder_ids.*' => ['integer', 'distinct', Rule::exists('share_holders', 'id')->where('company_id', $companyId)],
            'all' => ['nullable', 'boolean'],
        ]);

        $ids = $request->boolean('all') ? null : array_map('intval', $validated['share_holder_ids'] ?? []);
        $result = $this->accounts->generate($companyId, $ids, $this->currentEmployee());
        EnsureIdempotentRequest::doNotStore(['created']);

        return $this->message(
            sprintf('%d login account(s) created, %d linked to staff logins, %d skipped', count($result['created']), count($result['linked']), count($result['skipped'])),
            200,
            ['data' => $result],
        );
    }

    public function store(ShareHolder $shareHolder): JsonResponse
    {
        $this->authorizeAny('users.manage', 'capital.manage');

        $result = $this->accounts->provision($shareHolder, $this->currentEmployee());
        EnsureIdempotentRequest::doNotStore(['credentials']);

        return $this->message(
            $result['credentials'] !== null ? 'Login account created' : "Linked to the existing staff login of {$result['account']->full_name}",
            201,
            [
                'data' => $this->accounts->loginSummary($result['share_holder']->load('account')),
                'credentials' => $result['credentials'],
            ],
        );
    }

    public function resetPassword(ShareHolder $shareHolder): JsonResponse
    {
        $this->authorizeAny('users.manage');

        $credentials = $this->accounts->resetTemporaryPassword($shareHolder, $this->currentEmployee());
        EnsureIdempotentRequest::doNotStore(['credentials']);

        return $this->message('Temporary password generated — the shareholder must change it at the next login', 200, [
            'data' => $this->accounts->loginSummary($shareHolder->fresh('account')),
            'credentials' => $credentials,
        ]);
    }

    public function status(Request $request, ShareHolder $shareHolder): JsonResponse
    {
        $this->authorizeAny('users.manage');
        $validated = $request->validate(['active' => ['required', 'boolean']]);

        $this->accounts->setLoginActive($shareHolder, (bool) $validated['active'], $this->currentEmployee());

        return $this->message($validated['active'] ? 'Login activated' : 'Login deactivated', 200, [
            'data' => $this->accounts->loginSummary($shareHolder->fresh('account')),
        ]);
    }
}
