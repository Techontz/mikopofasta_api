<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records create/update/delete of a model in the audit trail with before/after values.
 */
trait Auditable
{
    /**
     * Attributes never written to the audit trail.
     *
     * @var list<string>
     */
    protected static array $auditExcluded = ['password', 'remember_token', 'bank_password', 'updated_at', 'created_at'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => static::writeAudit($model, 'created', null, $model->getAttributes()));
        static::updated(function (Model $model): void {
            $changes = $model->getChanges();
            if (array_diff(array_keys($changes), static::$auditExcluded) === []) {
                return;
            }
            static::writeAudit($model, 'updated', array_intersect_key($model->getOriginal(), $changes), $changes);
        });
        static::deleted(fn (Model $model) => static::writeAudit($model, 'deleted', $model->getOriginal(), null));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    protected static function writeAudit(Model $model, string $action, ?array $before, ?array $after): void
    {
        $clean = fn (?array $values): ?array => $values === null ? null : array_diff_key($values, array_flip(static::$auditExcluded));
        $user = request()?->user() ?? auth()->user() ?? auth('sanctum')->user();

        AuditLog::create([
            'company_id' => $model->getAttribute('company_id') ?? $user?->company_id,
            'employee_id' => $user?->getKey(),
            'action' => class_basename($model).'.'.$action,
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'before' => $clean($before),
            'after' => $clean($after),
            'ip_address' => request()?->ip(),
        ]);
    }
}
