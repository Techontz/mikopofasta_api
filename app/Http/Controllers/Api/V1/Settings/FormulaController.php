<?php

namespace App\Http\Controllers\Api\V1\Settings;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\InterestFormula;
use Illuminate\Http\JsonResponse;

/**
 * Settings → Interest Formula (live admin/formular_setting). Formulas are system-wide; enabling one makes it
 * selectable when defining loan categories.
 */
class FormulaController extends ApiController
{
    public function index(): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        return response()->json(['data' => InterestFormula::orderBy('id')->get()->map(fn (InterestFormula $formula): array => [
            'id' => $formula->id,
            'code' => $formula->code,
            'name' => $formula->name,
            'is_enabled' => $formula->is_enabled,
        ])]);
    }

    public function enable(InterestFormula $formula): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $formula->update(['is_enabled' => true]);

        return $this->message('Interest Formula Added successfully');
    }

    public function disable(InterestFormula $formula): JsonResponse
    {
        $this->authorizeAny('settings.manage');

        $formula->update(['is_enabled' => false]);

        return $this->message('Interest Formula Deleted successfully');
    }
}
