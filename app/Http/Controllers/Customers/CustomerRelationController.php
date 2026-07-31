<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\ManageCustomerRelationsAction;
use App\Domain\Customers\DTOs\GuarantorData;
use App\Domain\Customers\DTOs\NextOfKinData;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerNoteRequest;
use App\Http\Requests\Customers\StoreGuarantorRequest;
use App\Http\Requests\Customers\StoreNextOfKinRequest;
use App\Http\Resources\CustomerNoteResource;
use App\Http\Resources\GuarantorResource;
use App\Http\Resources\NextOfKinResource;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Models\NextOfKin;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarantors, next-of-kin and notes on a customer profile.
 *
 * All three are nested under the customer, so branch scope is checked once
 * against the parent and every child inherits it.
 */
final class CustomerRelationController extends Controller
{
    public function __construct(private readonly BranchScopeGuard $guard) {}

    /**
     * GET /api/v1/customers/{customer}/guarantors
     */
    public function guarantors(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeRead($request, $customer);

        return ApiResponse::data(GuarantorResource::collection($customer->guarantors()->latest()->get()));
    }

    /**
     * POST /api/v1/customers/{customer}/guarantors
     */
    public function storeGuarantor(StoreGuarantorRequest $request, Customer $customer, ManageCustomerRelationsAction $action): JsonResponse
    {
        $actor = $this->authorizeWrite($request, $customer);

        $guarantor = $action->addGuarantor($customer, GuarantorData::fromArray($request->validated()), $actor);

        return ApiResponse::data(new GuarantorResource($guarantor), status: Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/customers/{customer}/guarantors/{guarantor}
     */
    public function destroyGuarantor(Request $request, Customer $customer, Guarantor $guarantor, ManageCustomerRelationsAction $action): JsonResponse
    {
        $actor = $this->authorizeWrite($request, $customer);
        abort_unless($guarantor->customer_id === $customer->getKey(), Response::HTTP_NOT_FOUND);

        $action->removeGuarantor($guarantor, $actor);

        return ApiResponse::data(['message' => 'Guarantor removed.']);
    }

    /**
     * GET /api/v1/customers/{customer}/next-of-kin
     */
    public function nextOfKin(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeRead($request, $customer);

        return ApiResponse::data(NextOfKinResource::collection($customer->nextOfKin()->latest()->get()));
    }

    /**
     * POST /api/v1/customers/{customer}/next-of-kin
     */
    public function storeNextOfKin(StoreNextOfKinRequest $request, Customer $customer, ManageCustomerRelationsAction $action): JsonResponse
    {
        $actor = $this->authorizeWrite($request, $customer);

        $kin = $action->addNextOfKin($customer, NextOfKinData::fromArray($request->validated()), $actor);

        return ApiResponse::data(new NextOfKinResource($kin), status: Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/customers/{customer}/next-of-kin/{nextOfKin}
     */
    public function destroyNextOfKin(Request $request, Customer $customer, NextOfKin $nextOfKin, ManageCustomerRelationsAction $action): JsonResponse
    {
        $actor = $this->authorizeWrite($request, $customer);
        abort_unless($nextOfKin->customer_id === $customer->getKey(), Response::HTTP_NOT_FOUND);

        $action->removeNextOfKin($nextOfKin, $actor);

        return ApiResponse::data(['message' => 'Next of kin removed.']);
    }

    /**
     * GET /api/v1/customers/{customer}/notes
     */
    public function notes(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeRead($request, $customer);

        return ApiResponse::data(
            CustomerNoteResource::collection($customer->notes()->with('author')->latest()->get()),
        );
    }

    /**
     * POST /api/v1/customers/{customer}/notes
     */
    public function storeNote(StoreCustomerNoteRequest $request, Customer $customer): JsonResponse
    {
        $actor = $this->authorizeWrite($request, $customer);

        $note = $customer->notes()->create([
            'author_id' => $actor->getKey(),
            'note' => $request->validated('note'),
        ]);

        return ApiResponse::data(
            new CustomerNoteResource($note->load('author')),
            status: Response::HTTP_CREATED,
        );
    }

    private function authorizeRead(Request $request, Customer $customer): User
    {
        $this->authorize('view', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        return $actor;
    }

    private function authorizeWrite(Request $request, Customer $customer): User
    {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        return $actor;
    }
}
