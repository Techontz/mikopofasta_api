<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * The single write path for `audit_logs` (spec §2.1).
 *
 * "Kila action recorded" (§10) means the audit trail has to be written in the
 * same transaction as the change it describes — a committed change with a
 * rolled-back audit row, or vice versa, is worse than no audit trail at all
 * because it looks authoritative. Callers therefore invoke this inside their
 * own transaction rather than dispatching a job.
 *
 * Actor, IP and user-agent are resolved from the current request so call sites
 * do not have to thread them through every method signature.
 */
final class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function log(
        AuditAction $action,
        Model $auditable,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => ($actor ?? $this->currentUser())?->getKey(),
            'action' => $action->value,
            'auditable_type' => $auditable::class,
            'auditable_id' => $auditable->getKey(),
            'before_json' => $before,
            'after_json' => $after,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->truncatedUserAgent(),
            'created_at' => Date::now(),
        ]);
    }

    /**
     * Records an event that has no authenticated actor and no persisted
     * subject — a failed login being the case that matters. The attempted
     * identifier is kept so repeated attempts against one account are
     * traceable, but never the submitted password.
     *
     * @param array<string, mixed> $context
     */
    public function logAnonymous(AuditAction $action, string $auditableType, string $identifier, array $context = []): AuditLog
    {
        return AuditLog::create([
            'user_id' => null,
            'action' => $action->value,
            'auditable_type' => $auditableType,
            'auditable_id' => 0,
            'before_json' => null,
            'after_json' => array_merge(['identifier' => $identifier], $context),
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->truncatedUserAgent(),
            'created_at' => Date::now(),
        ]);
    }

    private function currentUser(): ?User
    {
        $user = $this->request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The column is VARCHAR(255) (§2.1); some agents are longer than that, and
     * a truncated agent beats a failed insert on an audit write.
     */
    private function truncatedUserAgent(): ?string
    {
        $agent = $this->request->userAgent();

        return $agent === null ? null : mb_substr($agent, 0, 255);
    }
}
