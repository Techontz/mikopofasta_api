<?php

namespace App\Services;

use App\Integrations\Linda\LindaGateway;
use App\Integrations\Linda\LindaResult;
use App\Models\Employee;
use App\Models\LindaWebhookEvent;
use App\Models\PhoneBondDevice;
use App\Models\PhoneBondLockRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * eSIMU lock and unlock through Linda, which are asynchronous: Linda's 202 only means the command is queued.
 *
 * - A request is stored first with its own idempotency key, the device shows Lock / Unlock Pending, then the command is
 *   sent. A network failure, 429 or 5xx keeps it pending and {@see self::sync()} resends it with the same key, so it can
 *   never be queued twice. A refusal (4xx) fails it at once and the device keeps its previous state.
 * - The device becomes Locked / Unlocked only when the phone confirms: Linda's webhook, or the command status (ACKED).
 *   A phone that reports a failure (command.failed / FAILED) fails the request and staff are alerted; a command still
 *   queued after the alert window (phone off) alerts staff once.
 */
class PhoneBondLocks
{
    public const ACTIONS = ['lock', 'unlock'];

    public function __construct(
        private LindaGateway $linda,
        private PhoneBondAudit $audit,
        private PhoneBondAlerts $alerts,
    ) {}

    /**
     * @param  'lock'|'unlock'  $action
     * @param  'auto'|'manual'  $trigger
     */
    public function request(PhoneBondDevice $device, string $action, string $trigger, string $reason, ?Employee $actor): PhoneBondLockRequest
    {
        $request = DB::transaction(function () use ($device, $action, $trigger, $reason, $actor): PhoneBondLockRequest {
            $device = PhoneBondDevice::lockForUpdate()->findOrFail($device->id);
            if ($device->status === PhoneBondDevice::CLOSED) {
                throw ValidationException::withMessages(['device' => 'A closed device is no longer managed by Linda.']);
            }
            if (! $device->isEnrolled() || blank($device->linda_device_id)) {
                throw ValidationException::withMessages(['device' => 'The device has not completed Linda enrollment.']);
            }
            $open = PhoneBondLockRequest::where('device_id', $device->id)->whereIn('action', self::ACTIONS)->where('status', PhoneBondLockRequest::PENDING)->first();
            if ($open !== null) {
                throw ValidationException::withMessages(['device' => "A {$open->action} request is still waiting for Linda's confirmation."]);
            }
            if ($action === 'lock' && $device->lock_state === PhoneBondDevice::LOCKED) {
                throw ValidationException::withMessages(['device' => 'The device is already locked.']);
            }
            if ($action === 'unlock' && $device->lock_state !== PhoneBondDevice::LOCKED) {
                throw ValidationException::withMessages(['device' => 'The device is not locked.']);
            }

            $request = PhoneBondLockRequest::create([
                'company_id' => $device->company_id,
                'device_id' => $device->id,
                'loan_id' => $device->loan_id,
                'action' => $action,
                'trigger' => $trigger,
                'reason' => mb_substr(trim($reason), 0, 500),
                'requested_by' => $actor?->id,
                'status' => PhoneBondLockRequest::PENDING,
                'previous_lock_state' => $device->lock_state,
            ]);
            $request->forceFill(['idempotency_key' => "mkopa-esimu-{$device->id}-{$action}-{$request->id}"])->save();
            $device->forceFill(['lock_state' => $action === 'lock' ? PhoneBondDevice::LOCK_PENDING : PhoneBondDevice::UNLOCK_PENDING])->save();
            $this->audit->record($device, "PhoneBond.{$action}_requested", $actor, ['request_id' => $request->id, 'trigger' => $trigger, 'reason' => $request->reason]);

            return $request;
        });

        return $this->send($request);
    }

    /**
     * Send (or resend, with the same idempotency key) a pending request to Linda.
     */
    public function send(PhoneBondLockRequest $request): PhoneBondLockRequest
    {
        $device = $request->device;
        $result = $request->action === 'lock'
            ? $this->linda->lock((string) $device->linda_device_id, $request->reason, (string) $request->idempotency_key)
            : $this->linda->unlock((string) $device->linda_device_id, $request->reason, (string) $request->idempotency_key);

        $request->forceFill(['attempts' => $request->attempts + 1, 'last_checked_at' => now()]);
        if ($result->ok) {
            $request->forceFill([
                'command_id' => $result->get('id'),
                'command_status' => $result->get('status'),
                'provider_reference' => $result->get('id'),
                'provider_message' => null,
                'provider_response' => $result->data,
            ])->save();

            return $result->get('status') === 'ACKED' ? $this->confirm($request, 'command') : $request;
        }

        if ($result->retryable) {
            $request->forceFill(['provider_message' => mb_substr((string) $result->message, 0, 500)])->save();

            return $request;
        }

        $request->save();

        return $this->fail($request, (string) $result->message, $result);
    }

    /**
     * The phone confirmed the command (webhook, command status, or a Linda portal reference recorded by staff).
     *
     * @param  'webhook'|'command'|'portal'  $source
     */
    public function confirm(PhoneBondLockRequest $request, string $source, ?string $reference = null, ?Employee $actor = null): PhoneBondLockRequest
    {
        return DB::transaction(function () use ($request, $source, $reference, $actor): PhoneBondLockRequest {
            $request = PhoneBondLockRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->status === PhoneBondLockRequest::CONFIRMED) {
                return $request;
            }

            $request->forceFill([
                'status' => PhoneBondLockRequest::CONFIRMED,
                'command_status' => $source === 'portal' ? $request->command_status : 'ACKED',
                'provider_reference' => $reference !== null ? mb_substr(trim($reference), 0, 100) : ($request->provider_reference ?? $request->command_id),
                'confirmed_at' => now(),
                'confirmed_by' => $actor?->id,
            ])->save();

            $device = PhoneBondDevice::lockForUpdate()->findOrFail($request->device_id);
            if ($this->isLatest($request) && in_array($request->action, self::ACTIONS, true)) {
                $device->forceFill([
                    'lock_state' => $request->action === 'lock' ? PhoneBondDevice::LOCKED : PhoneBondDevice::UNLOCKED,
                    'linda_status' => $request->action === 'lock' ? 'LOCKED' : 'ACTIVE',
                ])->save();
            }
            $this->audit->record($device, $request->action === 'lock' ? 'PhoneBond.locked' : 'PhoneBond.unlocked', $actor, [
                'request_id' => $request->id, 'trigger' => $request->trigger, 'reason' => $request->reason, 'result' => 'confirmed',
                'source' => $source, 'command_id' => $request->command_id, 'reference' => $request->provider_reference,
            ], $request->loan_id);

            return $request;
        });
    }

    /**
     * Staff record the reference of a lock / unlock done in the Linda portal for a pending or failed request.
     */
    public function confirmFromPortal(PhoneBondLockRequest $request, string $reference, Employee $actor): PhoneBondLockRequest
    {
        if ($request->status === PhoneBondLockRequest::CONFIRMED) {
            throw ValidationException::withMessages(['request' => 'This request is already confirmed.']);
        }
        if (! $this->isLatest($request)) {
            throw ValidationException::withMessages(['request' => 'A later request exists for this device; confirm that one instead.']);
        }

        return $this->confirm($request, 'portal', $reference, $actor);
    }

    public function fail(PhoneBondLockRequest $request, string $message, ?LindaResult $result = null): PhoneBondLockRequest
    {
        $request = DB::transaction(function () use ($request, $message, $result): PhoneBondLockRequest {
            $request = PhoneBondLockRequest::lockForUpdate()->findOrFail($request->id);
            if ($request->status !== PhoneBondLockRequest::PENDING) {
                return $request;
            }

            $request->forceFill([
                'status' => PhoneBondLockRequest::FAILED,
                'command_status' => $request->command_id !== null ? 'FAILED' : $request->command_status,
                'provider_message' => mb_substr($message, 0, 500) ?: 'Linda did not carry out the command.',
                'provider_response' => $result?->data ?: $request->provider_response,
            ])->save();

            $device = PhoneBondDevice::lockForUpdate()->findOrFail($request->device_id);
            if ($this->isLatest($request) && in_array($device->lock_state, [PhoneBondDevice::LOCK_PENDING, PhoneBondDevice::UNLOCK_PENDING], true)) {
                $device->forceFill(['lock_state' => $request->previous_lock_state ?? ($request->action === 'lock' ? PhoneBondDevice::UNLOCKED : PhoneBondDevice::LOCKED)])->save();
            }
            $this->audit->record($device, "PhoneBond.{$request->action}_failed", null, [
                'request_id' => $request->id, 'trigger' => $request->trigger, 'reason' => $request->reason, 'result' => 'failed',
                'message' => $request->provider_message, 'command_id' => $request->command_id,
            ], $request->loan_id);

            return $request;
        });

        if ($request->status === PhoneBondLockRequest::FAILED) {
            $device = $request->device;
            $this->alerts->toStaff($device, 'phone_bond.lock', 'esimu_command_failed', 'eSIMU '.$request->action.' failed',
                ucfirst($request->action)." of IMEI {$device->imei} failed: {$request->provider_message}");
        }

        return $request;
    }

    /**
     * Every few minutes: resend requests Linda has not received yet, ask Linda about queued commands, alert staff about
     * commands still waiting after the alert window.
     *
     * @return array{sent: int, confirmed: int, failed: int, alerted: int}
     */
    public function sync(CarbonImmutable $now): array
    {
        $counts = ['sent' => 0, 'confirmed' => 0, 'failed' => 0, 'alerted' => 0];
        $alertAfter = $now->subHours(max(1, (int) config('integrations.linda.command_alert_hours', 24)));

        PhoneBondLockRequest::query()->where('status', PhoneBondLockRequest::PENDING)->whereIn('action', self::ACTIONS)
            ->where(fn ($query) => $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $now->subMinute()))
            ->with('device')->orderBy('id')->limit(50)
            ->each(function (PhoneBondLockRequest $request) use ($alertAfter, &$counts): void {
                if ($request->command_id === null) {
                    $request = $this->send($request);
                    $counts['sent']++;
                } else {
                    $result = $this->linda->command($request->command_id);
                    $request->forceFill(['last_checked_at' => now()] + ($result->ok ? ['command_status' => $result->get('status')] : []))->save();
                    if ($result->ok && $result->get('status') === 'ACKED') {
                        $request = $this->confirm($request, 'command');
                    } elseif ($result->ok && in_array($result->get('status'), ['FAILED', 'EXPIRED'], true)) {
                        $request = $this->fail($request, 'The phone did not carry out the command ('.$result->get('status').').', $result);
                    }
                }

                $counts['confirmed'] += $request->status === PhoneBondLockRequest::CONFIRMED ? 1 : 0;
                $counts['failed'] += $request->status === PhoneBondLockRequest::FAILED ? 1 : 0;
                if ($request->status === PhoneBondLockRequest::PENDING && $request->alerted_at === null && $request->created_at->lte($alertAfter)) {
                    $request->forceFill(['alerted_at' => now()])->save();
                    $this->alerts->toStaff($request->device, 'phone_bond.lock', 'esimu_command_waiting', 'eSIMU '.$request->action.' still waiting',
                        ucfirst($request->action)." of IMEI {$request->device->imei} has not been confirmed by the phone since {$request->created_at->toDateTimeString()}. The phone may be off or out of signal.");
                    $counts['alerted']++;
                }
            });

        return $counts;
    }

    /**
     * Linda webhook: verify the signature over the raw body, apply each event once (Linda delivers at least once).
     *
     * @throws HttpException on a missing secret or a bad signature
     */
    public function webhook(string $rawBody, ?string $signature): void
    {
        $this->verify($rawBody, $signature);
        $event = json_decode($rawBody, true);
        if (! is_array($event) || blank($event['id'] ?? null) || blank($event['type'] ?? null)) {
            throw new HttpException(400, 'Malformed event.');
        }

        DB::transaction(function () use ($event): void {
            $inserted = LindaWebhookEvent::query()->insertOrIgnore([
                'event_id' => (string) $event['id'],
                'type' => (string) $event['type'],
                'payload' => json_encode($event),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted === 0) {
                return;
            }

            $this->apply((string) $event['type'], (array) ($event['data'] ?? []));
            LindaWebhookEvent::where('event_id', (string) $event['id'])->update(['processed_at' => now()]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function apply(string $type, array $data): void
    {
        if (! in_array($type, ['device.locked', 'device.unlocked', 'command.failed'], true)) {
            return;
        }

        $request = PhoneBondLockRequest::query()
            ->where(fn ($query) => $query->where('command_id', $data['commandId'] ?? '-')->orWhere('idempotency_key', $data['idempotencyKey'] ?? '-'))
            ->first();

        if ($request !== null) {
            if ($request->command_id === null && filled($data['commandId'] ?? null)) {
                $request->forceFill(['command_id' => $data['commandId']])->save();
            }
            $type === 'command.failed'
                ? $this->fail($request, 'The phone reported a failure'.(filled($data['detail'] ?? null) ? ': '.$data['detail'] : '.'))
                : $this->confirm($request, 'webhook');

            return;
        }

        // A lock or unlock done outside M-KopaCredit (e.g. in the Linda portal): record Linda's state on the device.
        $device = PhoneBondDevice::where('linda_device_id', $data['deviceId'] ?? '-')->first();
        if ($device !== null && $type !== 'command.failed') {
            $device->forceFill([
                'lock_state' => $type === 'device.locked' ? PhoneBondDevice::LOCKED : PhoneBondDevice::UNLOCKED,
                'linda_status' => $data['deviceStatus'] ?? ($type === 'device.locked' ? 'LOCKED' : 'ACTIVE'),
            ])->save();
            $this->audit->record($device, $type === 'device.locked' ? 'PhoneBond.locked' : 'PhoneBond.unlocked', null, [
                'result' => 'confirmed', 'source' => 'webhook (outside M-KopaCredit)', 'command_id' => $data['commandId'] ?? null,
            ]);
        }
    }

    private function verify(string $rawBody, ?string $signature): void
    {
        $secret = (string) config('integrations.linda.webhook_secret');
        if ($secret === '') {
            throw new HttpException(503, 'The Linda webhook is not configured.');
        }

        $parts = [];
        foreach (explode(',', (string) $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $parts[$key] = $value;
        }
        $timestamp = (int) ($parts['t'] ?? 0);
        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

        if ($timestamp === 0 || abs(time() - $timestamp) > 300 || ! hash_equals($expected, (string) ($parts['v1'] ?? ''))) {
            throw new HttpException(400, 'Invalid signature.');
        }
    }

    private function isLatest(PhoneBondLockRequest $request): bool
    {
        return ! PhoneBondLockRequest::where('device_id', $request->device_id)->where('id', '>', $request->id)->whereIn('action', self::ACTIONS)->exists();
    }
}
