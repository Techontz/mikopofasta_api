<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Domain\Auth\Enums\PermissionName;
use App\Domain\Organization\Services\GeographyImporter;
use App\Enums\AuditAction;
use App\Enums\ErrorCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\ImportGeographyRequest;
use App\Models\District;
use App\Models\Region;
use App\Models\Street;
use App\Models\Ward;
use App\Services\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Administration → Master Data → Geography.
 *
 * Behind `admin.org_settings`, the same permission every other configuration
 * screen sits behind. Reference data decides what an address can say, and an
 * officer who could add a ward could make one up.
 *
 * The import is idempotent, so this endpoint has no "replace" mode and no
 * delete: a wrong row is corrected in the source file and re-imported, and a
 * place nobody should choose is what `is_active` would be for if these tables
 * had it. Nothing here removes a region a customer is filed under.
 */
final class GeographyImportController extends Controller
{
    /**
     * GET /api/v1/master-data/geography — what is currently on file.
     *
     * Counts rather than rows: an administrator deciding whether to import
     * needs to know the country is a fifth loaded, not to page through it.
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        return ApiResponse::data([
            'regions' => Region::query()->count(),
            'districts' => District::query()->count(),
            'wards' => Ward::query()->count(),
            'streets' => Street::query()->count(),
            'columns' => GeographyImporter::COLUMNS,
            'maxRows' => GeographyImporter::MAX_ROWS,
        ]);
    }

    /**
     * POST /api/v1/master-data/geography/import
     */
    public function import(ImportGeographyRequest $request, GeographyImporter $importer, AuditLogger $audit): JsonResponse
    {
        $actor = $this->authorizeAdmin($request);

        $file = $request->file('file');

        try {
            $result = $importer->import($file->getRealPath());
        } catch (InvalidArgumentException $e) {
            /* A malformed header is the administrator's mistake to fix, not a
               server fault — say what is wrong and what was expected. */
            return ApiResponse::error(
                $e->getMessage(),
                ErrorCode::ValidationFailed,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        /* Audited because it changes what every future address may say, and a
           bad import is answered by knowing who ran which file when. */
        $audit->log(
            AuditAction::GeographyImported,
            /* Filed against the administrator who ran it: an import has no one
               subject, and "who loaded this register" is the question anybody
               asks about it afterwards. */
            $actor,
            after: [
                'file' => $file->getClientOriginalName(),
                'rows_read' => $result->rowsRead,
                'created' => $result->created,
                'rejected' => count($result->rejected),
            ],
            actor: $actor,
        );

        return ApiResponse::data($result->toArray());
    }

    private function authorizeAdmin(Request $request): \App\Models\User
    {
        $actor = $request->user();

        abort_if($actor === null, Response::HTTP_UNAUTHORIZED);
        abort_unless($actor->hasPermission(PermissionName::AdminOrgSettings), Response::HTTP_FORBIDDEN);

        return $actor;
    }
}
