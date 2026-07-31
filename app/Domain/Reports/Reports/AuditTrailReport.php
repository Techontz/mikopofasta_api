<?php

declare(strict_types=1);

namespace App\Domain\Reports\Reports;

use App\Domain\Reports\Contracts\Report;
use App\Domain\Reports\DTOs\ReportColumn;
use App\Domain\Reports\DTOs\ReportFilters;
use App\Domain\Reports\DTOs\ReportResult;
use App\Domain\Reports\Support\Cell;
use App\Models\AuditLog;

/**
 * `GET /reports/audit-trail` — every recorded action, who performed it, and
 * against what.
 *
 * Read straight from `audit_logs` — the same rows the per-entity Audit Trail
 * tabs display. §13 also routes cross-branch access attempts here, so a denied
 * request is itself an auditable event that shows up in this report.
 *
 * Capped rather than paginated: an audit trail is scanned for a period, and a
 * caller who needs more should narrow the window. Returning a hundred thousand
 * rows would help nobody.
 */
final class AuditTrailReport implements Report
{
    private const int MAX_ROWS = 500;

    public function slug(): string
    {
        return 'audit-trail';
    }

    public function title(): string
    {
        return 'Audit Trail';
    }

    public function description(): string
    {
        return 'Every recorded action, who performed it, and against what.';
    }

    public function group(): string
    {
        return 'Compliance';
    }

    public function supportedFilters(): array
    {
        return ['from', 'to', 'period'];
    }

    public function compute(ReportFilters $filters): ReportResult
    {
        $query = AuditLog::query()
            ->with('user')
            ->when($filters->from !== null, fn ($q) => $q->whereDate('created_at', '>=', $filters->from))
            ->when($filters->to !== null, fn ($q) => $q->whereDate('created_at', '<=', $filters->to))
            ->when(
                $filters->period !== null,
                fn ($q) => $q->whereRaw("DATE_FORMAT(created_at, '%Y-%m') = ?", [$filters->period]),
            );

        $total = (clone $query)->count();

        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::MAX_ROWS)->get()
            ->map(fn (AuditLog $log): array => [
                'at' => $log->created_at->toIso8601String(),
                'action' => str_replace('_', ' ', $log->action),
                'entity' => class_basename($log->auditable_type),
                'entityId' => (string) $log->auditable_id,
                'user' => Cell::pending($log->user?->name, 'System'),
                'ipAddress' => Cell::text($log->ip_address),
            ])->all();

        $summary = [['label' => 'Events', 'value' => (string) $total]];

        if ($total > count($rows)) {
            $summary[] = ['label' => 'Shown', 'value' => sprintf('%d (newest)', count($rows))];
        }

        return new ReportResult(
            columns: [
                ReportColumn::text('at', 'When'),
                ReportColumn::text('action', 'Action'),
                ReportColumn::text('entity', 'Entity'),
                ReportColumn::text('entityId', 'Reference'),
                ReportColumn::text('user', 'User'),
                ReportColumn::text('ipAddress', 'IP'),
            ],
            rows: $rows,
            summary: $summary,
            emptyMessage: 'No audit events in this window.',
            reconciliation: sprintf(
                'Read directly from audit_logs — the same rows the per-entity Audit Trail tabs display, including the BRANCH_SCOPE_VIOLATION events §13 records for denied cross-branch access. Newest %d shown of %d matching.',
                count($rows),
                $total,
            ),
        );
    }
}
