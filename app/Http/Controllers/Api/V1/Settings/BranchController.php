<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Settings\BranchRequest;
use App\Http\Resources\Api\V1\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Settings → Branch (live admin/blanch).
 */
class BranchController extends ApiController
{
    public function index(): AnonymousResourceCollection
    {
        $this->authorizeAny('settings.manage');

        $branches = Branch::where('company_id', $this->currentEmployee()->company_id)
            ->with(['region', 'zone'])
            ->withCount([
                'customers as active_count' => fn ($query) => $query->where('status', 'open'),
                'customers as pending_count' => fn ($query) => $query->where('status', 'pending'),
                'customers as default_count' => fn ($query) => $query->where('status', 'out'),
                'customers as done_count' => fn ($query) => $query->where('status', 'close'),
                'customers as all_count',
            ])
            ->orderBy('id')
            ->get();

        return BranchResource::collection($branches);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $branch = Branch::create($request->branchData() + ['company_id' => $this->currentEmployee()->company_id]);

        return $this->message('Branch Registered successfully', 201, ['data' => new BranchResource($branch)]);
    }

    public function show(Branch $branch): BranchResource
    {
        $this->authorizeAny('settings.manage');

        return new BranchResource($branch->load(['region', 'zone']));
    }

    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $branch->update($request->branchData());

        return $this->message('Branch Updated successfully', 200, ['data' => new BranchResource($branch)]);
    }

    public function destroy(Branch $branch): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        if ($branch->customers()->exists() || $branch->employees()->exists()) {
            return $this->message('Branch has customers or staff and cannot be deleted', 422);
        }

        $branch->delete();

        return $this->message('Branch Deleted successfully');
    }
}
