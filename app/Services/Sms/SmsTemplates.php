<?php

namespace App\Services\Sms;

use App\Models\SmsTemplate;

/**
 * The SMS the system sends by itself, worded by the company (SMS Centre → Templates):
 *  - payment_received: the "rejesho" receipt texted as soon as a repayment is allocated to a loan;
 *  - repayment_reminder: texted `days` days before an instalment falls due ({@see SmsReminders});
 *  - overdue_reminder: texted `days` days after an instalment was due and is still unpaid.
 * Each company starts with the default wording below; a template switched off is simply not sent. Placeholders in
 * braces ({name}, {amount}…) are filled from the payment or the instalment.
 */
class SmsTemplates
{
    public const PAYMENT_RECEIVED = 'payment_received';

    public const REPAYMENT_REMINDER = 'repayment_reminder';

    public const OVERDUE_REMINDER = 'overdue_reminder';

    /**
     * @var array<string, array{name: string, body: string, days: int|null, variables: list<string>}>
     */
    public const AUTOMATIC = [
        self::PAYMENT_RECEIVED => [
            'name' => 'Payment received (rejesho)',
            'body' => 'Tumepokea malipo yako ya TSH {amount}. Risiti: {receipt}. Salio la mkopo ni TSH {balance}. Asante, {company}.',
            'days' => null,
            'variables' => ['name', 'amount', 'receipt', 'balance', 'loan_number', 'date', 'company'],
        ],
        self::REPAYMENT_REMINDER => [
            'name' => 'Repayment reminder',
            'body' => 'Habari {name}, unakumbushwa rejesho la TSH {amount} la mkopo {loan_number} tarehe {due_date}. Asante, {company}.',
            'days' => 1,
            'variables' => ['name', 'amount', 'due_date', 'balance', 'loan_number', 'company'],
        ],
        self::OVERDUE_REMINDER => [
            'name' => 'Overdue reminder',
            'body' => 'Habari {name}, rejesho lako la TSH {amount} lililotakiwa tarehe {due_date} bado halijalipwa. Tafadhali lipa mapema. {company}.',
            'days' => 1,
            'variables' => ['name', 'amount', 'due_date', 'balance', 'loan_number', 'company'],
        ],
    ];

    /**
     * Placeholders an announcement may use: {name} is the customer's / contact's name, blank-safe.
     *
     * @var list<string>
     */
    public const ANNOUNCEMENT_VARIABLES = ['name', 'company'];

    /**
     * Create the company's automatic templates with the default wording when they do not exist yet.
     */
    public function ensureDefaults(int $companyId): void
    {
        foreach (self::AUTOMATIC as $key => $default) {
            SmsTemplate::firstOrCreate(
                ['company_id' => $companyId, 'key' => $key],
                ['type' => SmsTemplate::TYPE_AUTOMATIC, 'name' => $default['name'], 'body' => $default['body'], 'days' => $default['days'], 'is_active' => true],
            );
        }
    }

    public function template(int $companyId, string $key): SmsTemplate
    {
        $this->ensureDefaults($companyId);

        return SmsTemplate::where('company_id', $companyId)->where('key', $key)->firstOrFail();
    }

    /**
     * The automatic message with its placeholders filled, or null when the company switched the template off.
     *
     * @param  array<string, string|int|float|null>  $values
     */
    public function render(int $companyId, string $key, array $values): ?string
    {
        $template = $this->template($companyId, $key);

        return $template->is_active ? self::fill($template->body, $values) : null;
    }

    /**
     * @param  array<string, string|int|float|null>  $values
     */
    public static function fill(string $body, array $values): string
    {
        $replacements = [];
        foreach ($values as $key => $value) {
            $replacements['{'.$key.'}'] = (string) $value;
        }

        return trim((string) preg_replace('/ {2,}/', ' ', strtr($body, $replacements)));
    }
}
