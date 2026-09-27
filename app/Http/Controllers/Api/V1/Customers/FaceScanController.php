<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Customers\FaceVerifyRequest;
use App\Http\Resources\Api\V1\Customers\CustomerResource;
use App\Http\Resources\Api\V1\Customers\FaceScanResource;
use App\Models\Customer;
use App\Models\FaceScan;
use App\Services\Customers\FaceVerification;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Step 4 / Face KYC tab: record a liveness scan, list the scan history and stream a capture.
 */
class FaceScanController extends ApiController
{
    public function verify(FaceVerifyRequest $request, Customer $customer, FaceVerification $faces): CustomerResource
    {
        $faces->record(
            $customer,
            $request->file('capture'),
            $request->safe()->except('capture'),
            $this->currentEmployee(),
            $request->ip(),
            $request->userAgent(),
        );

        return new CustomerResource($customer->refresh()->load([...CustomerController::RESOURCE_RELATIONS, 'bankDetail', 'nextOfKins', 'guarantors', 'documents']));
    }

    public function index(Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        return FaceScanResource::collection($customer->faceScans()->with('scanner:id,first_name,middle_name,last_name')->latest('scanned_at')->latest('id')->get());
    }

    public function image(Customer $customer, FaceScan $scan): StreamedResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);
        abort_unless($scan->customer_id === $customer->id && Storage::disk(FaceScan::DISK)->exists($scan->photo_path), 404);

        return Storage::disk(FaceScan::DISK)->response($scan->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    private function assertAccessible(Customer $customer): void
    {
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);
    }
}
