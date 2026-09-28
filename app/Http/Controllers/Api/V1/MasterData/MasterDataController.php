<?php

namespace App\Http\Controllers\Api\V1\MasterData;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\MasterData\MasterDataItemRequest;
use App\Http\Resources\Api\V1\MasterData\MasterDataItemResource;
use App\Models\MasterData\MasterDataModel;
use App\Services\Customers\MasterDataRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Master-data lists used by customer registration (CUSTOMER_MODULE_IMPLEMENTATION.md §3.3). Reads are open to
 * every signed-in user (active rows); administration (inactive rows, create / update / delete) needs settings.manage.
 */
class MasterDataController extends ApiController
{
    public function __construct(private MasterDataRegistry $registry) {}

    /**
     * Every flat list, active rows: { data: { "<slug>": [ ... ] } }.
     */
    public function index(): JsonResponse
    {
        $lists = [];
        foreach ($this->registry->flatSlugs() as $slug) {
            $lists[$slug] = MasterDataItemResource::collection($this->query($slug, false)->get());
        }

        return response()->json(['data' => $lists]);
    }

    /**
     * One list. Parented lists may be filtered by parent_id; admins may pass includeInactive=1.
     */
    public function show(Request $request, string $list): JsonResponse
    {
        $parentColumn = $this->registry->parentColumn($list);
        $rows = $this->query($list, $this->includeInactive($request))
            ->when($parentColumn !== null && $request->filled('parent_id'), fn (Builder $query) => $query->where($parentColumn, $request->integer('parent_id')))
            ->get();

        return response()->json(['data' => MasterDataItemResource::collection($rows)]);
    }

    /**
     * Children of one parent; an empty list without parent_id.
     */
    public function parented(Request $request, string $list): JsonResponse
    {
        abort_unless($this->registry->isParented($list), 404, 'That list does not exist.');

        if (! $request->filled('parent_id')) {
            return response()->json(['data' => []]);
        }

        $rows = $this->query($list, $this->includeInactive($request))
            ->where($this->registry->parentColumn($list), $request->integer('parent_id'))
            ->get();

        return response()->json(['data' => MasterDataItemResource::collection($rows)]);
    }

    public function store(MasterDataItemRequest $request, string $list): JsonResponse
    {
        $class = $this->registry->modelClass($list);
        $data = $request->itemData();
        $parentColumn = $this->registry->parentColumn($list);

        /** @var MasterDataModel|null $restored */
        $restored = $class::onlyTrashed()
            ->when($parentColumn !== null, fn (Builder $query) => $query->where($parentColumn, $data[$parentColumn]))
            ->where('code', $data['code'])
            ->first();

        if ($restored !== null) {
            $restored->fill($data);
            $restored->deleted_at = null;
            $restored->save();
            $item = $restored;
        } else {
            $item = $class::query()->create($data + ['created_by' => $this->currentEmployee()->id]);
        }

        return $this->message('Saved.', 201, ['data' => new MasterDataItemResource($item)]);
    }

    public function update(MasterDataItemRequest $request, string $list, int $id): JsonResponse
    {
        $item = $this->findOrFail($list, $id);
        $item->update($request->itemData());

        return $this->message('Updated.', 200, ['data' => new MasterDataItemResource($item)]);
    }

    public function destroy(string $list, int $id): JsonResponse
    {
        $this->authorizeAny('settings.manage');
        $item = $this->findOrFail($list, $id);

        abort_if($list === 'document-types' && $item->code === 'kyc_attachment', 409, 'The KYC Attachment document type is used by registration and cannot be deleted.');

        $item->delete();

        return $this->message('Deleted.');
    }

    /**
     * @return Builder<MasterDataModel>
     */
    private function query(string $slug, bool $includeInactive): Builder
    {
        $class = $this->registry->modelClass($slug);
        abort_if($class === null, 404, 'That list does not exist.');

        return $class::query()->when(! $includeInactive, fn (Builder $query) => $query->where('is_active', true))->orderBy('sort_order')->orderBy('name');
    }

    private function findOrFail(string $slug, int $id): MasterDataModel
    {
        $item = $this->registry->find($slug, $id);
        abort_if($item === null, 404, 'That record does not exist.');

        return $item;
    }

    private function includeInactive(Request $request): bool
    {
        return $request->boolean('includeInactive') && Gate::allows('settings.manage');
    }
}
