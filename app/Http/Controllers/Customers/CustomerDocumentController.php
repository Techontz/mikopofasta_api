<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\ManageCustomerDocumentsAction;
use App\Domain\Customers\Services\KycDocumentStorage;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\UploadDocumentRequest;
use App\Http\Resources\CustomerDocumentResource;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Customer KYC documents.
 *
 * Files live on the private `kyc` disk and are only ever reachable through the
 * signed download route below — the stored path never appears in a response.
 */
final class CustomerDocumentController extends Controller
{
    public function __construct(private readonly BranchScopeGuard $guard) {}

    /**
     * GET /api/v1/customers/{customer}/documents
     */
    public function index(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $this->guard->authorizeBranchId($this->actor($request), $customer->branch_id, Customer::class);

        return ApiResponse::data(
            CustomerDocumentResource::collection($customer->documents()->latest()->get()),
        );
    }

    /**
     * POST /api/v1/customers/{customer}/documents
     */
    public function store(
        UploadDocumentRequest $request,
        Customer $customer,
        ManageCustomerDocumentsAction $action,
    ): JsonResponse {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        $file = $request->file('file');

        abort_unless($file instanceof UploadedFile, Response::HTTP_UNPROCESSABLE_ENTITY);

        $document = $action->upload(
            $customer,
            $file,
            (string) $request->validated('documentType'),
            $actor,
        );

        return ApiResponse::data(new CustomerDocumentResource($document), status: Response::HTTP_CREATED);
    }

    /**
     * DELETE /api/v1/customers/{customer}/documents/{document}
     */
    public function destroy(
        Request $request,
        Customer $customer,
        CustomerDocument $document,
        ManageCustomerDocumentsAction $action,
    ): JsonResponse {
        $this->authorize('update', $customer);
        $actor = $this->actor($request);
        $this->guard->authorizeBranchId($actor, $customer->branch_id, Customer::class);

        // Route-model binding resolves the two independently, so a document id
        // from another customer would otherwise be deletable through this
        // customer's URL.
        abort_unless($document->customer_id === $customer->getKey(), Response::HTTP_NOT_FOUND);

        $action->remove($document, $actor);

        return ApiResponse::data(['message' => 'Document removed.']);
    }

    /**
     * GET /api/v1/customers/{customer}/documents/{document}/download
     *
     * Signed and short-lived. The signature is what authorises the request —
     * the URL is handed to a browser that cannot attach a bearer token to a
     * plain navigation, which is exactly why spec §1 calls for signed URLs
     * here rather than ordinary token auth.
     */
    public function download(Request $request, Customer $customer, CustomerDocument $document, KycDocumentStorage $storage): StreamedResponse
    {
        abort_unless($document->customer_id === $customer->getKey(), Response::HTTP_NOT_FOUND);
        abort_unless($storage->exists($document->file_path), Response::HTTP_NOT_FOUND);

        return response()->streamDownload(
            function () use ($storage, $document): void {
                $stream = $storage->readStream($document->file_path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $document->original_name ?? basename($document->file_path),
            [
                'Content-Type' => $document->mime_type ?? 'application/octet-stream',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }

    /**
     * GET /api/v1/customers/{customer}/photo
     *
     * The liveness capture, on the same signed-URL terms as documents.
     */
    public function photo(Request $request, Customer $customer, KycDocumentStorage $storage): StreamedResponse
    {
        abort_if($customer->photo_path === null, Response::HTTP_NOT_FOUND);
        abort_unless($storage->exists($customer->photo_path), Response::HTTP_NOT_FOUND);

        $path = $customer->photo_path;

        return response()->streamDownload(
            function () use ($storage, $path): void {
                $stream = $storage->readStream($path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            'liveness-'.$customer->customer_number.'.jpg',
            [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }
}
