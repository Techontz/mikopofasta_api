<?php

namespace App\Integrations\Linda;

/**
 * Linda Device Lock Partner API v1 (https://api.linda.co.tz/v1/partner), selected by config('integrations.linda.driver').
 * Only documented operations are declared. Loans, schedules and debts stay in M-KopaCredit: Linda has no endpoint for them,
 * nor for releasing or swapping a phone (done in the Linda portal).
 */
interface LindaGateway
{
    /**
     * POST /registrations - the customer and the phone together; safe to repeat (matched by externalId / IMEI).
     *
     * @param  array{externalId: string, fullName: string, phone: string, nationalId?: string|null, idDocumentType?: string|null, address?: string|null}  $customer
     * @param  array{externalId: string, imei: string, make?: string|null, model?: string|null, serialNumber?: string|null}  $device
     */
    public function register(array $customer, array $device): LindaResult;

    /**
     * GET /devices/{id}/enrollment - a fresh enrollment QR payload for a phone that has not enrolled (409 once enrolled).
     */
    public function enrollment(string $deviceId): LindaResult;

    /**
     * GET /devices/{id} - the device's status and its latest command.
     */
    public function device(string $deviceId): LindaResult;

    /**
     * POST /devices/{id}/lock - queued (202), or the original command when the idempotency key repeats (200).
     */
    public function lock(string $deviceId, string $reason, string $idempotencyKey): LindaResult;

    /**
     * POST /devices/{id}/unlock.
     */
    public function unlock(string $deviceId, string $reason, string $idempotencyKey): LindaResult;

    /**
     * GET /commands/{id} - QUEUED, SENT, ACKED, FAILED or EXPIRED.
     */
    public function command(string $commandId): LindaResult;
}
