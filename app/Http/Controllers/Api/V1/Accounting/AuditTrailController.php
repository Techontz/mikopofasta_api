<?php

namespace App\Http\Controllers\Api\V1\Accounting;

use App\Http\Controllers\Api\V1\ApiController;
use App\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accounting → Audit Trail (ACCOUNT OVERVIEW "KILA KITENDO KIWE NA AUDIT TRAIL", OVERVIEW ALL REPORT
 * "Transaction Audit Trail: who did what, when, before vs after").
 */
class AuditTrailController extends ApiController
{
    private const LIMIT = 2000;

    public function index(Request $request): JsonResponse
    {
        $this->authorizeAny('audit.view');

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'employee_id' => ['nullable', 'integer'],
            'model' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
        ]);

        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::today()->subDays(30);
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today();

        $logs = AuditLog::query()
            ->where('company_id', $this->currentEmployee()->company_id)
            ->whereDate('created_at', '>=', $from->toDateString())
            ->whereDate('created_at', '<=', $to->toDateString())
            ->when($request->filled('employee_id'), fn ($query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('model'), fn ($query) => $query->where('auditable_type', 'like', '%\\\\'.$request->string('model')->toString()))
            ->when($request->filled('action'), fn ($query) => $query->where('action', 'like', '%'.$request->string('action')->toString().'%'))
            ->with('employee:id,first_name,middle_name,last_name')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get();

        return response()->json(['data' => $logs->map(fn (AuditLog $log): array => [
            'id' => $log->id,
            'created_at' => $log->created_at?->toDateTimeString(),
            'employee_id' => $log->employee_id,
            'employee' => $log->employee?->full_name ?? 'SYSTEM',
            'action' => $log->action,
            'event' => str_contains($log->action, '.') ? substr($log->action, strrpos($log->action, '.') + 1) : $log->action,
            'model' => $log->auditable_type ? $this->modelLabel(class_basename($log->auditable_type)) : null,
            'model_id' => $log->auditable_id,
            'changes' => $this->diff($log->before, $log->after),
            'before' => $log->before,
            'after' => $log->after,
            'context' => $log->context,
            'ip_address' => $log->ip_address,
        ])]);
    }

    /**
     * Distinct audited models for the filter dropdown.
     */
    public function models(): JsonResponse
    {
        $this->authorizeAny('audit.view');

        $models = AuditLog::query()
            ->where('company_id', $this->currentEmployee()->company_id)
            ->whereNotNull('auditable_type')
            ->distinct()
            ->pluck('auditable_type')
            ->map(fn (string $type): string => class_basename($type))
            ->unique()->sort()->values()
            ->map(fn (string $name): array => ['value' => $name, 'label' => $this->modelLabel($name)]);

        return response()->json(['data' => $models]);
    }

    /**
     * The record name shown to users: classes whose table keeps a historical name are shown by their UI name.
     */
    private function modelLabel(string $basename): string
    {
        return ['CustomerCategory' => 'CustomerType'][$basename] ?? $basename;
    }

    /**
     * Field-by-field before/after list.
     *
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @return list<array{field: string, before: mixed, after: mixed}>
     */
    private function diff(?array $before, ?array $after): array
    {
        $fields = array_unique(array_merge(array_keys($before ?? []), array_keys($after ?? [])));
        $changes = [];
        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($old !== $new) {
                $changes[] = ['field' => (string) $field, 'before' => $old, 'after' => $new];
            }
        }

        return $changes;
    }
}
