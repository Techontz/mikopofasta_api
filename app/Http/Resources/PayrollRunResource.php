<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayrollRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `PayrollRunSchema` in the frontend's types/payroll.ts.
 *
 * @mixin PayrollRun
 */
final class PayrollRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'period' => $this->period,
            'status' => $this->status->value,
            'generatedBy' => (string) $this->generated_by,
            'finalizedAt' => $this->finalized_at?->toIso8601String(),

            'lines' => PayrollLineResource::collection($this->whenLoaded('lines')),

            // Summary figures the payroll list shows per run, computed here
            // rather than in the browser so a total can never disagree with
            // the lines it came from.
            'lineCount' => $this->whenLoaded('lines', fn (): int => $this->lines->count()),
            'netTotal' => $this->whenLoaded('lines', fn (): string => $this->netTotal()->toDecimalString()),
        ];
    }
}
