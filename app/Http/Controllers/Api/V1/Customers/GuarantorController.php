<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Customers\CustomerRelationRowRequest;
use App\Http\Resources\Api\V1\Customers\GuarantorResource;
use App\Models\Customer;
use App\Models\Guarantor;
use App\Services\Customers\CustomerRegistrar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Profile → Guarantors tab.
 */
class GuarantorController extends ApiController
{
    public function __construct(private CustomerRegistrar $registrar) {}

    public function index(Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        return GuarantorResource::collection($customer->guarantors()->orderBy('id')->get());
    }

    public function store(CustomerRelationRowRequest $request, Customer $customer): JsonResponse
    {
        $optional = fn (string $key): ?string => $request->filled($key) ? trim($request->string($key)->toString()) : null;

        $guarantor = $customer->guarantors()->create([
            'name' => trim($request->string('name')->toString()),
            'phone' => trim($request->string('phone')->toString()),
            'nida_number' => $optional('nidaNumber'),
            'relationship' => $request->string('relationship')->toString(),
            'address' => $optional('address'),
            'occupation' => $optional('occupation'),
        ]);
        $this->registrar->audit($customer, 'Customer.guarantor_added', ['guarantor_id' => $guarantor->id, 'name' => $guarantor->name]);

        return (new GuarantorResource($guarantor))->response()->setStatusCode(201);
    }

    public function destroy(Customer $customer, Guarantor $guarantor): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $this->assertAccessible($customer);
        abort_unless($guarantor->customer_id === $customer->id, 404);

        $guarantor->delete();
        $this->registrar->audit($customer, 'Customer.guarantor_removed', [], ['guarantor_id' => $guarantor->id, 'name' => $guarantor->name]);

        return $this->message('Guarantor removed.');
    }

    private function assertAccessible(Customer $customer): void
    {
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);
    }
}
