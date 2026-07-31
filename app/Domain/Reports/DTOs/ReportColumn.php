<?php

declare(strict_types=1);

namespace App\Domain\Reports\DTOs;

/**
 * One column of a report, mirroring the frontend's `ReportColumn`.
 *
 * The rendering hints travel with the data because the report defines what a
 * figure IS — money, a percentage, a count — and a generic table renderer
 * cannot infer that from a JSON number. Sending them keeps one definition of
 * each report rather than one here and one in the client.
 */
final readonly class ReportColumn
{
    private function __construct(
        public string $key,
        public string $label,
        public string $align,
        public bool $money,
        public bool $percent,
    ) {}

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, 'left', false, false);
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, 'right', false, false);
    }

    public static function money(string $key, string $label): self
    {
        return new self($key, $label, 'right', true, false);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, 'right', false, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'align' => $this->align,
            'money' => $this->money,
            'percent' => $this->percent,
        ];
    }
}
