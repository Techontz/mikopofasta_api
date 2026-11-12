<?php

namespace App\Integrations\Linda;

use Illuminate\Support\Facades\Cache;

/**
 * Deterministic driver for automated tests. A device's Linda id is "ld-{imei}"; the IMEI's last digit chooses the outcome:
 *  - 8: Linda is unreachable (registration and commands are retried later) - as is every call while
 *    config('integrations.linda.test_outage') is true;
 *  - 7: registration conflicts (409: the IMEI belongs to another customer);
 *  - 9: the phone never finishes enrollment;
 *  - 0: lock / unlock commands stay queued (phone offline);
 *  - 6: the phone reports a lock / unlock failure;
 *  - otherwise the phone enrolls at once and acknowledges commands when asked for their status.
 * Lock state lives in the cache so the device endpoint answers LOCKED after an acknowledged lock.
 */
class TestLindaGateway implements LindaGateway
{
    public function register(array $customer, array $device): LindaResult
    {
        $imei = (string) $device['imei'];
        if (str_ends_with($imei, '8') || config('integrations.linda.test_outage')) {
            return LindaResult::unreachable('Linda could not be reached: test outage');
        }
        if (str_ends_with($imei, '7')) {
            return new LindaResult(false, 409, ['message' => 'This device is already registered to a different customer'], 'Linda 409: This device is already registered to a different customer');
        }

        return new LindaResult(true, 201, [
            'customer' => ['id' => 'lc-'.$customer['externalId'], 'externalId' => $customer['externalId']],
            'device' => $this->devicePayload($imei, true) + ['enrollment' => $this->enrollmentPayload($imei)],
            'created' => ['customer' => true, 'device' => true],
        ]);
    }

    public function enrollment(string $deviceId): LindaResult
    {
        $imei = $this->imei($deviceId);
        if (str_ends_with($imei, '8')) {
            return LindaResult::unreachable('Linda could not be reached: test outage');
        }
        if (! str_ends_with($imei, '9')) {
            return new LindaResult(false, 409, [], 'Linda 409: This device has already enrolled; there is nothing to scan');
        }

        return new LindaResult(true, 200, $this->devicePayload($imei, true) + ['enrollment' => $this->enrollmentPayload($imei)]);
    }

    public function device(string $deviceId): LindaResult
    {
        $imei = $this->imei($deviceId);
        if (str_ends_with($imei, '8') || config('integrations.linda.test_outage')) {
            return LindaResult::unreachable('Linda could not be reached: test outage');
        }

        return new LindaResult(true, 200, $this->devicePayload($imei, str_ends_with($imei, '9')));
    }

    public function lock(string $deviceId, string $reason, string $idempotencyKey): LindaResult
    {
        return $this->queue('LOCK', $deviceId, $reason, $idempotencyKey);
    }

    public function unlock(string $deviceId, string $reason, string $idempotencyKey): LindaResult
    {
        return $this->queue('UNLOCK', $deviceId, $reason, $idempotencyKey);
    }

    public function command(string $commandId): LindaResult
    {
        [, $type, $imei] = explode('|', $commandId) + [null, null, ''];
        $status = match (true) {
            str_ends_with((string) $imei, '0') => 'QUEUED',
            str_ends_with((string) $imei, '6') => 'FAILED',
            default => 'ACKED',
        };
        if ($status === 'ACKED') {
            Cache::forever("linda-test:locked:{$imei}", $type === 'LOCK');
        }

        return new LindaResult(true, 200, ['id' => $commandId, 'deviceId' => 'ld-'.$imei, 'type' => $type, 'status' => $status]);
    }

    private function queue(string $type, string $deviceId, string $reason, string $idempotencyKey): LindaResult
    {
        $imei = $this->imei($deviceId);
        if (str_ends_with($imei, '8') || config('integrations.linda.test_outage')) {
            return LindaResult::unreachable('Linda could not be reached: test outage');
        }

        return new LindaResult(true, 202, ['id' => "cmd|{$type}|{$imei}|{$idempotencyKey}", 'deviceId' => $deviceId, 'type' => $type, 'status' => 'QUEUED', 'reason' => $reason, 'idempotentReplay' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function devicePayload(string $imei, bool $pending): array
    {
        return [
            'id' => 'ld-'.$imei,
            'imei' => $imei,
            'status' => $pending ? 'PENDING_ENROLLMENT' : (Cache::get("linda-test:locked:{$imei}") ? 'LOCKED' : 'ACTIVE'),
            'lastCheckInAt' => $pending ? null : now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentPayload(string $imei): array
    {
        return [
            'token' => 'enroll-'.$imei,
            'expiresAt' => now()->addDays(7)->toIso8601String(),
            'qr' => ['android.app.extra.PROVISIONING_DEVICE_ADMIN_COMPONENT_NAME' => 'com.devicelock.agent/.LockAdminReceiver', 'token' => 'enroll-'.$imei],
        ];
    }

    private function imei(string $deviceId): string
    {
        return str_starts_with($deviceId, 'ld-') ? substr($deviceId, 3) : $deviceId;
    }
}
