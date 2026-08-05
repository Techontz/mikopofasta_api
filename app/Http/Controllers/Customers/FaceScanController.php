<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customers;

use App\Domain\Customers\Services\KycDocumentStorage;
use App\Domain\Organization\Services\BranchScopeGuard;
use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\FaceScanResource;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\FaceScan;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Face KYC history — the read side of the biometric module.
 *
 * Writes go through KycController::faceVerify, which is where a scan is
 * created. Everything here answers questions about scans that already exist:
 * what was captured, by whom, and on what evidence.
 *
 * All three endpoints are branch-scoped (§13) and authorised against the
 * customer's own policy. A face scan is the most sensitive artefact this
 * system holds, so nothing about it is reachable by anyone who could not open
 * the customer's profile in the first place.
 */
final class FaceScanController extends Controller
{
    public function __construct(private readonly BranchScopeGuard $guard) {}

    /**
     * GET /api/v1/customers/{customer}/face-scans
     *
     * Newest first, active one included and flagged. The whole history, not a
     * page of it: a customer accumulates scans one re-verification at a time,
     * and the profile shows all of them.
     */
    public function index(Request $request, Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $this->guard->authorizeBranchId($this->actor($request), $customer->branch_id, Customer::class);

        return ApiResponse::data(
            FaceScanResource::collection($customer->faceScans()->with('operator')->get()),
        );
    }

    /**
     * GET /api/v1/customers/{customer}/face-scans/{faceScan}/image
     *
     * Signed rather than bearer-authenticated: the URL is handed to an <img>
     * tag, which cannot carry an Authorization header, so the signature is the
     * credential. Same terms as documents and the profile photo.
     */
    public function image(Customer $customer, FaceScan $faceScan, KycDocumentStorage $storage): StreamedResponse
    {
        /* A scan id from another customer's history would otherwise stream
           that customer's biometric image under this customer's signature. */
        abort_unless($faceScan->customer_id === $customer->getKey(), Response::HTTP_NOT_FOUND);
        abort_unless($storage->exists($faceScan->photo_path), Response::HTTP_NOT_FOUND);

        $path = $faceScan->photo_path;

        return response()->streamDownload(
            function () use ($storage, $path): void {
                $stream = $storage->readStream($path);

                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            sprintf('face-scan-%s-%d.jpg', $customer->customer_number, $faceScan->getKey()),
            [
                'Content-Type' => 'image/jpeg',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
        );
    }

    /**
     * GET /api/v1/customers/{customer}/face-scans/{faceScan}/audit
     *
     * Everything on the record about one verification, assembled for export:
     * the measurements, the operator, the device and address it came from, and
     * the audit-log entries that mention it.
     *
     * Returned as data rather than a file. The frontend serialises it and
     * offers the download, which keeps the bearer token on the server — a
     * browser-navigable export route would need its own signed URL and would
     * be one more way to reach biometric metadata.
     */
    public function audit(Request $request, Customer $customer, FaceScan $faceScan): JsonResponse
    {
        $this->authorize('view', $customer);
        $this->guard->authorizeBranchId($this->actor($request), $customer->branch_id, Customer::class);

        abort_unless($faceScan->customer_id === $customer->getKey(), Response::HTTP_NOT_FOUND);

        $faceScan->load('operator');

        /*
         * The trail for this customer, narrowed to entries that name this
         * scan. `after_json->face_scan_id` is written by
         * VerifyCustomerFaceAction; the before/after pair means a scan appears
         * both when it was taken and when it was superseded.
         */
        $entries = AuditLog::query()
            ->where('auditable_type', Customer::class)
            ->where('auditable_id', $customer->getKey())
            ->where('action', AuditAction::CustomerFaceScanned->value)
            ->with('user')
            ->orderByDesc('created_at')
            ->get()
            ->filter(fn (AuditLog $log): bool => ($log->after_json['face_scan_id'] ?? null) === $faceScan->getKey()
                || ($log->before_json['face_scan_id'] ?? null) === $faceScan->getKey())
            ->values();

        return ApiResponse::data([
            'customer' => [
                'id' => (string) $customer->getKey(),
                'customerNumber' => $customer->customer_number,
                'fullName' => $customer->fullName(),
            ],
            'scan' => new FaceScanResource($faceScan),
            'auditTrail' => $entries->map(fn (AuditLog $log): array => [
                'id' => (string) $log->getKey(),
                'action' => $log->action,
                'operator' => $log->user?->name,
                'ipAddress' => $log->ip_address,
                'userAgent' => $log->user_agent,
                'before' => $log->before_json,
                'after' => $log->after_json,
                'at' => $log->created_at->toIso8601String(),
            ])->all(),
            'generatedAt' => now()->toIso8601String(),
            'generatedBy' => $this->actor($request)->name,
        ]);
    }
}
