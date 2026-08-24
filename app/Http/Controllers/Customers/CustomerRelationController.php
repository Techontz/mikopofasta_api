<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Actions\ManageCustomerRelationsAction;
use App\Domain\Customers\DTOs\GuarantorData;
use App\Domain\Customers\DTOs\NextOfKinData;
use App\Domain\Customers\Services\KycDocumentStorage;
use App\Domain\Organization\Services\BranchScope;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
     * GET /api/v1/guarantors?search=&limit= — every guarantor on record.
     *
     * WHY THIS EXISTS. Guarantors are stored per customer, and every other
     * endpoint reads them that way. The loan application's "Import Guarantors"
     * step needs the opposite view: somebody who already stood for one customer
     * is very often the same person standing for the next, and the branch keeps
     * re-typing their name, phone and ID by hand — which is how the same
     * guarantor ends up on the book three times with three spellings.
     *
     * READ-ONLY, and it creates nothing. Importing is still an ordinary
     * `POST /customers/{customer}/guarantors`: the chosen record is copied onto
     * the new customer, with its own relationship, as a row of its own. A
     * guarantor row belongs to the customer it guarantees, and sharing one
     * between two customers would mean deleting it from one silently removed it
     * from the other.
     *
     * BRANCH SCOPED, through the customer each guarantor belongs to. §13 does
     * not stop applying because the record is one level down — without the
     * join, this endpoint would be a way to read every branch's guarantor book,
     * names and phone numbers included.
     */
    public function index(Request $request, BranchScope $scope): JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $search = trim((string) $request->query('search', ''));

        $query = Guarantor::query()
            /* `middle_name` is in the list because `Customer::fullName()` reads it —
               a narrowed select that omits a column the accessor touches throws
               MissingAttributeException at render time, not at query time. */
            ->with('customer:id,customer_number,first_name,middle_name,last_name,branch_id')
            ->whereHas(
                'customer',
                fn ($q) => $scope->applyToColumn($q, $this->actor($request)),
            );

        if ($search !== '') {
            $like = '%'.$search.'%';
            /* The three things an officer has to hand when importing: the
               name they remember, the phone on the file, or the ID number. */
            $query->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('nida_number', 'like', $like);
            });
        }

        /*
         * Capped rather than paginated. This feeds a type-ahead: nobody scrolls
         * a guarantor list to page four, they type another letter. The cap
         * keeps an unfiltered open of the control cheap on a large book.
         */
        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        return ApiResponse::data(
            GuarantorResource::collection($query->orderBy('name')->limit($limit)->get()),
        );
    }

    /**
     * GET /api/v1/guarantors/{guarantor}/passport — the stored photograph.
     *
     * On the SIGNED route group, outside Sanctum, because the URL is handed to
     * a browser as an `<img src>` or a download link and neither can carry an
     * Authorization header. The signature is the credential, and it expires
     * after KycDocumentStorage::URL_TTL_MINUTES — the same terms every other
     * KYC file in this application is served on.
     */
    public function passport(Guarantor $guarantor, KycDocumentStorage $storage): StreamedResponse
    {
        abort_if($guarantor->passport_path === null, Response::HTTP_NOT_FOUND);
        abort_unless($storage->exists($guarantor->passport_path), Response::HTTP_NOT_FOUND);

        $path = $guarantor->passport_path;

        return response()->streamDownload(
            function () use ($storage, $path): void {
                $stream = $storage->readStream($path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $guarantor->passport_original_name ?? 'guarantor-passport',
            [
                /* The stored type, not a guessed one: a passport may be a PDF
                   or an image, unlike the always-JPEG liveness capture. */
                'Content-Type' => $guarantor->passport_mime_type ?? 'application/octet-stream',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                /* Regulated documents are never executed by the browser. */
                'Content-Security-Policy' => "default-src 'none'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

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

        /*
         * An import names the record whose passport to copy. It is resolved and
         * BRANCH-CHECKED here: `exists:guarantors,id` proves the row is real,
         * not that this officer may see it, and an id alone must not be a way
         * to pull a document across branches.
         */
        $copyFrom = null;
        $sourceId = $request->validated('copyPassportFromGuarantorId');

        if ($sourceId !== null) {
            $copyFrom = Guarantor::query()->with('customer')->findOrFail($sourceId);
            $this->guard->authorizeBranchId($actor, $copyFrom->customer?->branch_id, Customer::class);
        }

        $guarantor = $action->addGuarantor(
            $customer,
            GuarantorData::fromArray($request->validated()),
            $actor,
            /* `file()` rather than `validated()`: an UploadedFile does not
               survive the validated-array round trip intact. */
            $request->file('passport'),
            $copyFrom,
        );

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
