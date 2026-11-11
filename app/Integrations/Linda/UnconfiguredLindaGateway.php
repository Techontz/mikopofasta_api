<?php

namespace App\Integrations\Linda;

/**
 * Default driver until LINDA_DRIVER=http and a key are set: every call is refused (not retried), so nothing is ever reported
 * registered, enrolled, locked or unlocked without Linda. Staff act in the Linda portal and record its reference.
 */
class UnconfiguredLindaGateway implements LindaGateway
{
    public const MESSAGE = 'The Linda integration is not configured (LINDA_DRIVER and LINDA_API_KEY). Act in the Linda portal and record its reference.';

    public function register(array $customer, array $device): LindaResult
    {
        return $this->refuse();
    }

    public function enrollment(string $deviceId): LindaResult
    {
        return $this->refuse();
    }

    public function device(string $deviceId): LindaResult
    {
        return $this->refuse();
    }

    public function lock(string $deviceId, string $reason, string $idempotencyKey): LindaResult
    {
        return $this->refuse();
    }

    public function unlock(string $deviceId, string $reason, string $idempotencyKey): LindaResult
    {
        return $this->refuse();
    }

    public function command(string $commandId): LindaResult
    {
        return $this->refuse();
    }

    private function refuse(): LindaResult
    {
        return new LindaResult(false, 0, [], self::MESSAGE);
    }
}
