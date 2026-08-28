<?php

declare(strict_types=1);

namespace App\Domain\Organization\DTOs;

/**
 * What an import actually did, per level, plus every row it refused.
 *
 * Created and skipped are reported separately because on a re-run they are the
 * whole story: a second import of the same file should create nothing and skip
 * everything, and a summary that only said "4,000 rows processed" could not
 * tell that apart from having duplicated the country.
 */
final readonly class GeographyImportResult
{
    /**
     * @param array<string, int> $created Level => rows inserted.
     * @param array<string, int> $existing Level => rows already present.
     * @param list<array{row: int, reason: string}> $rejected
     */
    public function __construct(
        public array $created,
        public array $existing,
        public array $rejected,
        public int $rowsRead,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rowsRead' => $this->rowsRead,
            'created' => $this->created,
            'existing' => $this->existing,
            'rejected' => $this->rejected,
            'rejectedCount' => count($this->rejected),
        ];
    }
}
