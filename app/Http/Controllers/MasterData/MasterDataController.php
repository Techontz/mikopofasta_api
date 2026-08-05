<?php

declare(strict_types=1);

namespace App\Http\Controllers\MasterData;

use App\Domain\Auth\Enums\PermissionName;
use App\Http\Controllers\Controller;
use App\Http\Resources\MasterDataResource;
use App\Models\MasterData\AccountType;
use App\Models\MasterData\Bank;
use App\Models\MasterData\CustomerType;
use App\Models\MasterData\DocumentType;
use App\Models\MasterData\EmploymentType;
use App\Models\MasterData\LoanType;
use App\Models\MasterData\MaritalStatusOption;
use App\Models\MasterData\MasterDataModel;
use App\Models\MasterData\MobileMoneyProvider;
use App\Models\MasterData\Occupation;
use App\Models\MasterData\WorkType;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
    ];

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
        $data['created_by'] = $request->user()?->getKey();

        return ApiResponse::data(new MasterDataResource($model::query()->create($data)), [], 201);
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
        $map = ['sortOrder' => 'sort_order', 'isActive' => 'is_active'];

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
