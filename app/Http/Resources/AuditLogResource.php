<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Matches `AuditLogSchema` in the frontend's types/audit.ts.
 *
 * `auditableType` is emitted as the fully-qualified class name it is stored as
 * — `App\Models\Loan` — plus a short form beside it. The long one is what makes
 * a row traceable back to the record; the short one is what a person reads.
 * Replacing rather than supplementing would make the audit trail less precise
 * than the thing it audits.
 *
 * @mixin AuditLog
 */
final class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'userId' => $this->user_id === null ? null : (string) $this->user_id,
            'action' => $this->action,
            'auditableType' => $this->auditable_type,
            'auditableId' => (string) $this->auditable_id,
            'beforeJson' => $this->before_json,
            'afterJson' => $this->after_json,
            'ipAddress' => $this->ip_address,
            'userAgent' => $this->user_agent,
            'createdAt' => $this->created_at?->toIso8601String(),

            // "Loan", not "App\Models\Loan" — for the table's Record column.
            'auditableLabel' => class_basename($this->auditable_type),

            /*
             * The actor's name, so the table need not fetch every user to
             * render a column. Null for an anonymous event — a failed login has
             * no user yet, and that is a fact worth showing rather than hiding.
             */
            'userName' => $this->whenLoaded(
                'user',
                fn (): ?string => $this->user_id === null ? null : $this->user->name,
            ),
        ];
    }
}
