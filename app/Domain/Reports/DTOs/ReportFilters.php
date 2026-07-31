<?php

declare(strict_types=1);

namespace App\Domain\Reports\DTOs;

use Carbon\CarbonImmutable;

/**
 * The four filters every report endpoint accepts — §15.6's
 * `?branch_id=&period=&from=&to=`.
 *
 * Immutable, and carried through every report rather than passed as loose
 * arguments: a report that took its own parameters could honour a filter
 * differently from its neighbour, and two reports over the same data would
 * disagree.
 */
final readonly class ReportFilters
{
    public function __construct(
        public ?int $branchId = null,
        public ?string $period = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * @param array<string, mixed> $query
     */
    public static function fromArray(array $query): self
    {
        return new self(
            branchId: isset($query['branch_id']) && $query['branch_id'] !== '' ? (int) $query['branch_id'] : null,
            period: self::nullableString($query['period'] ?? null),
            from: self::nullableString($query['from'] ?? null),
            to: self::nullableString($query['to'] ?? null),
        );
    }

    /** A copy scoped to one branch — how §13 pins a report to the caller's own. */
    public function forBranch(?int $branchId): self
    {
        return new self($branchId, $this->period, $this->from, $this->to);
    }

    /**
     * Only the filters a report actually declares support for.
     *
     * An unsupported parameter is dropped rather than silently changing the
     * result, so `meta.filters_applied` never claims a filter the figures did
     * not honour.
     *
     * @param list<string> $supported
     */
    public function only(array $supported): self
    {
        return new self(
            branchId: in_array('branchId', $supported, true) ? $this->branchId : null,
            period: in_array('period', $supported, true) ? $this->period : null,
            from: in_array('from', $supported, true) ? $this->from : null,
            to: in_array('to', $supported, true) ? $this->to : null,
        );
    }

    /**
     * Whether a date falls inside this window.
     *
     * Compared as `YYYY-MM-DD` strings, which sort lexicographically in date
     * order — the same comparison the frontend makes, and one that cannot be
     * thrown off by a timezone.
     */
    public function covers(CarbonImmutable|string|null $date): bool
    {
        if ($date === null) {
            return false;
        }

        $day = $date instanceof CarbonImmutable ? $date->toDateString() : substr($date, 0, 10);

        if ($this->from !== null && $day < $this->from) {
            return false;
        }

        if ($this->to !== null && $day > $this->to) {
            return false;
        }

        return $this->period === null || str_starts_with($day, $this->period);
    }

    public function hasDateWindow(): bool
    {
        return $this->from !== null || $this->to !== null || $this->period !== null;
    }

    /**
     * What `meta.filters_applied` echoes — the wire names from §15.6, not the
     * internal camelCase, so a caller sees back exactly what they sent.
     *
     * @return array<string, string>
     */
    public function applied(): array
    {
        return array_filter([
            'branch_id' => $this->branchId === null ? null : (string) $this->branchId,
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
        ], static fn (?string $v): bool => $v !== null);
    }

    private static function nullableString(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
