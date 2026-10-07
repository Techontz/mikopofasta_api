<?php

namespace App\Services\Sms;

use App\Integrations\Sms\SmsGateway;
use App\Models\SmsLog;
use Throwable;

/**
 * Sends one SMS through the configured connector and records it in sms_logs with its category (payment, reminder,
 * overdue, announcement) and outcome. A gateway failure is recorded as "failed" and reported, never thrown: an SMS
 * must not undo the payment or the reminder run that triggered it.
 */
class SmsSender
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public function __construct(private readonly SmsGateway $gateway) {}

    /**
     * @param  array{customer_id?: int|null, employee_id?: int|null, reference?: string|null}  $context
     */
    public function send(int $companyId, string $phone, string $message, string $category, array $context = []): SmsLog
    {
        $log = $this->record($companyId, $phone, $message, $category, $context);
        $this->deliver($log);

        return $log;
    }

    /**
     * Record the SMS as pending; {@see self::deliver()} sends it later (announcements go out after the response).
     *
     * @param  array{customer_id?: int|null, employee_id?: int|null, reference?: string|null}  $context
     */
    public function record(int $companyId, string $phone, string $message, string $category, array $context = []): SmsLog
    {
        return SmsLog::create([
            'company_id' => $companyId,
            'customer_id' => $context['customer_id'] ?? null,
            'employee_id' => $context['employee_id'] ?? null,
            'phone' => self::normalisePhone($phone) ?? $phone,
            'message' => $message,
            'category' => $category,
            'status' => self::STATUS_PENDING,
            'reference' => $context['reference'] ?? null,
        ]);
    }

    public function deliver(SmsLog $log): void
    {
        try {
            $this->gateway->send($log->phone, $log->message);
            $log->update(['status' => self::STATUS_SENT, 'error' => null]);
        } catch (Throwable $exception) {
            report($exception);
            $log->update(['status' => self::STATUS_FAILED, 'error' => mb_substr($exception->getMessage(), 0, 250)]);
        }
    }

    /**
     * Tanzanian number in 255XXXXXXXXX form (accepts 07…, 7…, +255…, 255…), or null when it is not a phone number.
     */
    public static function normalisePhone(?string $phone): ?string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        return match (true) {
            strlen($digits) === 12 && str_starts_with($digits, '255') => $digits,
            strlen($digits) === 10 && str_starts_with($digits, '0') => '255'.substr($digits, 1),
            strlen($digits) === 9 && ! str_starts_with($digits, '0') => '255'.$digits,
            default => null,
        };
    }
}
