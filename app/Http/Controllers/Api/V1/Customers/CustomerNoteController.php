<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Resources\Api\V1\Customers\CustomerNoteResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Profile → Notes tab.
 */
class CustomerNoteController extends ApiController
{
    public function index(Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        return CustomerNoteResource::collection($customer->notes()->with('author:id,first_name,middle_name,last_name')->latest('id')->get());
    }

    public function store(Request $request, Customer $customer): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $this->assertAccessible($customer);

        $validated = $request->validate(['body' => ['required', 'string', 'max:2000']]);
        $note = $customer->notes()->create(['body' => trim($validated['body']), 'created_by' => $this->currentEmployee()->id]);

        return (new CustomerNoteResource($note->load('author')))->response()->setStatusCode(201);
    }

    private function assertAccessible(Customer $customer): void
    {
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);
    }
}
