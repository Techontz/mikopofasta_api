<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\Auth\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Resources\MasterDataResource;
use App\Models\Customer;
use App\Models\MasterData\AccountType;
use App\Models\MasterData\Bank;
use App\Models\MasterData\ContractType;
use App\Models\MasterData\CustomerType;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\Employer;
use App\Models\MasterData\EmploymentType;
use App\Models\MasterData\IdType;
use App\Models\MasterData\LoanType;
use App\Models\MasterData\MaritalStatusOption;
use App\Models\MasterData\MasterDataModel;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\Occupation;
use App\Models\MasterData\Sector;
use App\Models\MasterData\SectorCategory;
use App\Models\MasterData\WorkType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CRUD for every admin-managed lookup list.
 *
 * One controller for nine tables, because they are nine instances of the same
 * thing and nine copies of this file would drift. The list is named in the
 * route — `/api/v1/master-data/loan-types` — and resolved against the map
 * below, so an unknown name 404s rather than reaching the database.
 *
 * READS are open to any authenticated user: the registration form needs them,
 * and a loan officer must be able to see the list they are choosing from.
 * WRITES need `admin.org_settings`, the same permission that gates every other
 * configuration screen.
 *
 * `?active=1` is what forms ask for. The admin screen omits it and sees
 * disabled entries too, which is the only way to re-enable one.
 */
final class MasterDataController extends Controller
{
    /**
     * @var array<string, class-string<MasterDataModel>>
     */
    private const array LISTS = [
        'loan-types' => LoanType::class,
        'customer-types' => CustomerType::class,
        'account-types' => AccountType::class,
        'work-types' => WorkType::class,
        'employment-types' => EmploymentType::class,
        'occupations' => Occupation::class,
        'banks' => Bank::class,
        'mobile-money-providers' => MobileMoneyProvider::class,
        'marital-statuses' => MaritalStatusOption::class,
        /* KYC document types — what a category's required_documents names. */
        'document-types' => DocumentType::class,
        /* Which identity document was seen, and on what terms somebody is
           employed — see the 2026_08_30 migrations. */
        'id-types' => IdType::class,
        'contract-types' => ContractType::class,
        /* The employing body. Its cadres are NOT in this map: they belong to a
           sector and are served by sectorCategories() below, which filters on
           the parent the way the address lookups filter on region. */
        'sectors' => Sector::class,
        /* Private companies. A SEPARATE list from `sectors`: a ministry has
           cadres inside it and a company does not, and one list would offer a
           public servant a sugar mill to serve in. */
        'employers' => Employer::class,
    ];

    /**
     * GET /api/v1/master-data/sector-categories?sector_id=
     *
     * The one lookup list with a parent. It is not in LISTS because every
     * entry there is a flat list the generic handler can serve whole, and
     * returning every cadre of every sector to a form that has already chosen
     * one would be the same mistake the address step avoided.
     *
     * Reads are open to any authenticated user, as with every other list here:
     * the registration form needs them.
     */
    public function sectorCategories(Request $request): JsonResponse
    {
        $query = SectorCategory::query()
            ->forSector($request->filled('sector_id') ? $request->integer('sector_id') : null);

        $query = $request->boolean('active')
            ? $query->where('is_active', true)->orderByRaw('sort_order IS NULL, sort_order')->orderBy('name')
            : $query->orderByRaw('sort_order IS NULL, sort_order')->orderBy('name');

        return ApiResponse::data(MasterDataResource::collection($query->get()));
    }

    /**
     * POST /api/v1/master-data/sector-categories
     *
     * The cadre write path. Without it a sector could be created and never
     * populated, which makes the sector itself useless — the registration
     * form asks for both levels and refuses a cadre that belongs to another
     * sector.
     *
     * `code` is unique WITHIN its sector, not globally: two employing bodies
     * may each have an "Administration" cadre and they are not the same job.
     */
    public function storeSectorCategory(Request $request): JsonResponse
    {
        $this->requireOrgSettings($request);

        $data = $this->toColumns($request->validate($this->sectorCategoryRules(null, null)));

        $row = new SectorCategory;
        $row->sector_id = (int) $data['sector_id'];
        $row->code = $data['code'];
        $row->name = $data['name'];
        $row->description = $data['description'] ?? null;
        $row->sort_order = $data['sort_order'] ?? null;
        $row->is_active = (bool) ($data['is_active'] ?? true);
        $row->created_by = $request->user()?->getKey();
        $row->save();

        return ApiResponse::data(new MasterDataResource($row), [], 201);
    }

    /**
     * PUT /api/v1/master-data/sector-categories/{id}
     */
    public function updateSectorCategory(Request $request, int $id): JsonResponse
    {
        $this->requireOrgSettings($request);

        $row = SectorCategory::query()->findOrFail($id);
        $rules = $this->sectorCategoryRules($id, $row->sector_id);

        $row->update($this->toColumns($request->validate($rules)));

        return ApiResponse::data(new MasterDataResource($row->refresh()));
    }

    /**
     * DELETE /api/v1/master-data/sector-categories/{id}
     *
     * Refused while a customer is filed under it. Soft-deleting a cadre
     * somebody's record points at would leave a profile that cannot say what
     * the customer does; disabling it is the ordinary action, and this is for
     * entries created in error.
     */
    public function destroySectorCategory(Request $request, int $id): JsonResponse
    {
        $this->requireOrgSettings($request);

        $row = SectorCategory::query()->findOrFail($id);

        abort_if(
            Customer::query()->where('sector_category_id', $id)->exists(),
            Response::HTTP_CONFLICT,
            'Customers are filed under this sector category. Deactivate it instead of deleting it.',
        );

        $row->delete();

        return ApiResponse::data(['removed' => true]);
    }

    public function index(Request $request, string $list): JsonResponse
    {
        $model = $this->resolve($list);

        $query = $request->boolean('active')
            ? $model::query()->selectable()
            : $model::query()->orderByRaw('sort_order IS NULL, sort_order')->orderBy('name');

        return ApiResponse::data(MasterDataResource::collection($query->get()));
    }

    public function store(Request $request, string $list): JsonResponse
    {
        $this->requireOrgSettings($request);
        $model = $this->resolve($list);

        $data = $this->toColumns($request->validate($this->rules($model, null)));

        /* Assigned field by field rather than through a `mixed` array: every
           lookup list shares these six columns and nothing else is writable
           here, so naming them is both safer and clearer than a bag. */
        $row = new $model;
        $row->code = $data['code'];
        $row->name = $data['name'];
        $row->description = $data['description'] ?? null;
        $row->sort_order = $data['sort_order'] ?? null;
        $row->is_active = (bool) ($data['is_active'] ?? true);
        $row->created_by = $request->user()?->getKey();
        $row->save();

        return ApiResponse::data(new MasterDataResource($row), [], 201);
    }

    public function update(Request $request, string $list, int $id): JsonResponse
    {
        $this->requireOrgSettings($request);
        $model = $this->resolve($list);
        $row = $model::query()->findOrFail($id);

        $row->update($this->toColumns($request->validate($this->rules($model, $id))));

        return ApiResponse::data(new MasterDataResource($row->refresh()));
    }

    /**
     * Soft delete.
     *
     * A list entry customers already reference must never vanish: the foreign
     * keys are `restrictOnDelete`, so a hard delete would fail anyway, and a
     * customer whose loan type disappeared is a record that cannot be read.
     * Disabling (`is_active = false`) is the usual action; this is for entries
     * created in error.
     */
    public function destroy(Request $request, string $list, int $id): JsonResponse
    {
        $this->requireOrgSettings($request);
        $row = $this->resolve($list)::query()->findOrFail($id);
        $row->delete();

        return ApiResponse::data(['removed' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function sectorCategoryRules(?int $ignore, ?int $sectorId): array
    {
        /* On update the sector is fixed: moving a cadre between employing
           bodies would silently rewrite what every customer under it does. */
        $sector = $ignore === null
            ? ['required', 'integer', Rule::exists('sectors', 'id')->whereNull('deleted_at')]
            : ['prohibited'];

        return [
            'sectorId' => $sector,
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique('sector_categories', 'code')
                    ->ignore($ignore)
                    ->whereNull('deleted_at')
                    ->where('sector_id', $sectorId ?? request()->integer('sectorId')),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The API speaks camelCase; the columns are snake_case.
     *
     * Every other resource in this system converts at the boundary, so these
     * do too rather than exposing `sort_order` to the client and making this
     * one list the odd one out.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function toColumns(array $input): array
    {
        $map = ['sortOrder' => 'sort_order', 'isActive' => 'is_active', 'sectorId' => 'sector_id'];

        $columns = [];
        foreach ($input as $key => $value) {
            $columns[$map[$key] ?? $key] = $value;
        }

        return $columns;
    }

    /**
     * Writes are configuration, so they carry the same permission every other
     * configuration screen does rather than inventing a new one.
     */
    private function requireOrgSettings(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermissionTo(PermissionName::AdminOrgSettings->value) ?? false,
            403,
        );
    }

    /**
     * @param class-string<MasterDataModel> $model
     * @return array<string, mixed>
     */
    private function rules(string $model, ?int $ignore): array
    {
        $table = (new $model)->getTable();

        return [
            'code' => [
                'required', 'string', 'max:40',
                Rule::unique($table, 'code')->ignore($ignore)->whereNull('deleted_at'),
            ],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'sortOrder' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return class-string<MasterDataModel>
     */
    private function resolve(string $list): string
    {
        return self::LISTS[$list] ?? throw new NotFoundHttpException("Unknown master data list: {$list}");
    }
}
