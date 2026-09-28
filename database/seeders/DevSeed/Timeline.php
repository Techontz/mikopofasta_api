<?php

namespace Database\Seeders\DevSeed;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Carbon as IlluminateCarbon;

/**
 * Chronological event queue driven by the test clock. Every business action is registered at the moment it happens
 * (e.g. "2026-04-08 11:00 manager approves loan L03") and executed in date order, so dated postings land in the right
 * accounting period before that period is closed. Each event carries an "already done" check against natural keys, so a
 * second run skips everything that exists.
 */
final class Timeline
{
    /**
     * @var list<array{at: CarbonImmutable, seq: int, label: string, run: Closure(): void, done: (Closure(): bool)|null}>
     */
    private array $events = [];

    private int $sequence = 0;

    /**
     * @var array{executed: int, skipped: int}
     */
    private array $stats = ['executed' => 0, 'skipped' => 0];

    /**
     * @param  Closure(): void  $run
     * @param  (Closure(): bool)|null  $done
     */
    public function at(string|CarbonImmutable $at, string $label, Closure $run, ?Closure $done = null): void
    {
        $this->events[] = [
            'at' => $at instanceof CarbonImmutable ? $at : CarbonImmutable::parse($at),
            'seq' => $this->sequence++,
            'label' => $label,
            'run' => $run,
            'done' => $done,
        ];
    }

    /**
     * @param  Closure(string): void  $log
     * @return array{executed: int, skipped: int}
     */
    public function run(Closure $log): array
    {
        usort($this->events, fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['seq']] <=> [$b['at']->getTimestamp(), $b['seq']]);

        foreach ($this->events as $event) {
            self::travelTo($event['at']);

            if ($event['done'] !== null && ($event['done'])()) {
                $this->stats['skipped']++;
                $log("{$event['at']->format('Y-m-d H:i')} = already done: {$event['label']}");

                continue;
            }

            try {
                ($event['run'])();
            } catch (\Throwable $exception) {
                throw new \RuntimeException("[{$event['at']->toDateTimeString()}] {$event['label']}: {$exception->getMessage()}", 0, $exception);
            }
            $this->stats['executed']++;
            $log("{$event['at']->format('Y-m-d H:i')} {$event['label']}");
        }

        $this->events = [];

        return $this->stats;
    }

    public static function travelTo(CarbonImmutable $at): void
    {
        Carbon::setTestNow($at);
        IlluminateCarbon::setTestNow($at);
        CarbonImmutable::setTestNow($at);
    }

    public static function travelBack(): void
    {
        Carbon::setTestNow();
        IlluminateCarbon::setTestNow();
        CarbonImmutable::setTestNow();
    }
}
