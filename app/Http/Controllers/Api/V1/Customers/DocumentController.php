<?php

namespace App\Http\Controllers\Api\V1\Customers;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\Customers\StoreCustomerDocumentRequest;
use App\Http\Resources\Api\V1\Customers\CustomerDocumentResource;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Services\Customers\CustomerRegistrar;
use App\Services\Customers\KycStatusCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer documents on the private disk (CUSTOMER_MODULE_IMPLEMENTATION.md §5.6). The wizard uploads one
 * `kyc_attachment`; further documents are added from the profile.
 */
class DocumentController extends ApiController
{
    /** Private disk holding customer documents. */
    public const DISK = 'local';

    public function __construct(private KycStatusCalculator $kyc, private CustomerRegistrar $registrar) {}

    public function index(Customer $customer): AnonymousResourceCollection
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);

        return CustomerDocumentResource::collection($customer->documents()->with('uploader:id,first_name,middle_name,last_name')->latest('id')->get());
    }

    public function store(StoreCustomerDocumentRequest $request, Customer $customer): JsonResponse
    {
        $file = $request->file('file');
        $path = $file->store('customers/'.$customer->id.'/documents', self::DISK);

        $document = $customer->documents()->create([
            'document_type' => $request->string('documentType')->toString(),
            'file_path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 250),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $this->currentEmployee()->id,
        ]);

        $this->registrar->audit($customer, 'Customer.document_uploaded', ['document_id' => $document->id, 'document_type' => $document->document_type]);
        $this->kyc->refresh($customer);

        return (new CustomerDocumentResource($document->load('uploader')))->response()->setStatusCode(201);
    }

    public function download(Customer $customer, CustomerDocument $document): StreamedResponse
    {
        $this->authorizeAny('customers.view');
        $this->assertAccessible($customer);
        abort_unless($document->customer_id === $customer->id && Storage::disk(self::DISK)->exists($document->file_path), 404);

        return Storage::disk(self::DISK)->download($document->file_path, $document->original_name);
    }

    public function destroy(Customer $customer, CustomerDocument $document): JsonResponse
    {
        $this->authorizeAny('customers.manage');
        $this->assertAccessible($customer);
        abort_unless($document->customer_id === $customer->id, 404);

        Storage::disk(self::DISK)->delete($document->file_path);
        $document->delete();

        $this->registrar->audit($customer, 'Customer.document_deleted', [], ['document_id' => $document->id, 'document_type' => $document->document_type]);
        $this->kyc->refresh($customer);

        return $this->message('Document deleted.');
    }

    private function assertAccessible(Customer $customer): void
    {
        abort_unless($this->scoped(Customer::query())->whereKey($customer->id)->exists(), 404);
    }
}
