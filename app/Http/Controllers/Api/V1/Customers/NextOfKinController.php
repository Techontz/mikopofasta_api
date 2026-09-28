<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Customers\CustomerRelationRowRequest;
use App\Http\Resources\Api\V1\Customers\NextOfKinResource;
use App\Models\Customer;
use App\Models\CustomerNextOfKin;
use App\Services\Customers\CustomerRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Profile → Next of Kin tab.
 */
class NextOfKinController extends ApiController
{
    public function __construct(private CustomerRegistrar $registrar) {}

    public function index(Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        return NextOfKinResource::collection($customer->nextOfKins()->orderBy('id')->get());
    }

    public function store(CustomerRelationRowRequest $request, Customer $customer): JsonResponse
    {
        $row = $customer->nextOfKins()->create([
            'name' => trim($request->string('name')->toString()),
            'relationship' => $request->string('relationship')->toString(),
            'phone' => trim($request->string('phone')->toString()),
            'address' => $request->filled('address') ? trim($request->string('address')->toString()) : null,
        ]);
        $this->registrar->audit($customer, 'Customer.next_of_kin_added', ['next_of_kin_id' => $row->id, 'name' => $row->name]);

        return (new NextOfKinResource($row))->response()->setStatusCode(201);
    }

    public function destroy(Customer $customer, CustomerNextOfKin $nextOfKin): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $this->assertAccessible($customer);
        abort_unless($nextOfKin->customer_id === $customer->id, 404);

        $nextOfKin->delete();
        $this->registrar->audit($customer, 'Customer.next_of_kin_removed', [], ['next_of_kin_id' => $nextOfKin->id, 'name' => $nextOfKin->name]);

        return $this->message('Next of kin removed.');
    }

    private function assertAccessible(Customer $customer): void
    {
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);
    }
}
