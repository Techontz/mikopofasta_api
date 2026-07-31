<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\ErrorCode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single place the spec §1 response envelope is constructed.
 *
 *   success: { "data": ..., "meta": { "pagination": {...} } }
 *   error:   { "message": "...", "error_code": "...", "errors": { ... } }
 *
 * A note on casing, because it is inconsistent by necessity rather than by
 * accident. The frontend is the contract, and it pins three things:
 *
 *   - Resource attributes are camelCase. types/user.ts declares `branchId`,
 *     `lastLoginAt`, `deletedAt` and validates responses with Zod, and there
 *     is no snake→camel mapping layer anywhere in lib/api/. Returning
 *     `branch_id` would fail validation at the boundary.
 *   - `meta.pagination` is camelCase: lib/api/types.ts declares
 *     `{ page, perPage, total }`.
 *   - The error envelope is snake_case: lib/api/errors.ts reads `error_code`.
 *   - Query parameters are snake_case: features/reports/report-filters.tsx
 *     builds `?branch_id=&period=&from=&to=`, and spec §1 specifies
 *     `?page=&per_page=`.
 *
 * All four are enforced here and in the base resource, so if the casing
 * convention is ever revisited it changes in one place rather than across
 * every controller.
 */
final class ApiResponse
{
    public const int MAX_PER_PAGE = 100;

    public const int DEFAULT_PER_PAGE = 15;

    /**
     * @param array<string, mixed> $meta
     */
    public static function data(mixed $data, array $meta = [], int $status = Response::HTTP_OK): JsonResponse
    {
        $payload = ['data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Wraps a paginator, projecting each item through the given resource class
     * and emitting `meta.pagination` in the shape the frontend declares.
     *
     * @param LengthAwarePaginator<int, covariant \Illuminate\Database\Eloquent\Model> $paginator
     * @param class-string<JsonResource> $resource
     * @param array<string, mixed> $extraMeta
     */
    public static function paginated(
        LengthAwarePaginator $paginator,
        string $resource,
        array $extraMeta = [],
    ): JsonResponse {
        return self::data(
            $resource::collection($paginator->items()),
            array_merge([
                'pagination' => self::pagination($paginator),
            ], $extraMeta),
        );
    }

    /**
     * @param LengthAwarePaginator<int, covariant \Illuminate\Database\Eloquent\Model> $paginator
     * @return array{page: int, perPage: int, total: int, lastPage: int}
     */
    public static function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'page' => $paginator->currentPage(),
            'perPage' => $paginator->perPage(),
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
        ];
    }

    /**
     * @param array<string, list<string>> $errors
     */
    public static function error(
        string $message,
        ErrorCode $errorCode,
        int $status = Response::HTTP_BAD_REQUEST,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'message' => $message,
            'error_code' => $errorCode->value,
        ];

        // Present only for validation failures, per spec §1.
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Resolves the effective `per_page`, clamped to the spec §1 maximum of 100.
     */
    public static function perPage(mixed $requested): int
    {
        $perPage = is_numeric($requested) ? (int) $requested : self::DEFAULT_PER_PAGE;

        return max(1, min($perPage, self::MAX_PER_PAGE));
    }

    /**
     * @param ResourceCollection<covariant JsonResource> $collection
     */
    public static function collection(ResourceCollection $collection): JsonResponse
    {
        return self::data($collection);
    }
}
