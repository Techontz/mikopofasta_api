<?php

namespace App\Services\Credit;

/**
 * One line of the "Why this recommendation?" breakdown (§37B): Data → Analysis → Recommendation.
 *
 *  - DATA      `evidence` — the raw figures and the tables / services they were read from.
 *  - ANALYSIS  `value` — those figures normalised to 0 – 1, where 1 is the healthiest reading.
 *  - EFFECT    `contribution` — the points this factor adds to the 100-point score
 *              (`weight` × `value` × 100, never above `max_contribution`).
 *
 * A factor never carries a bare number: `summary` says in words what the figures mean and
 * `evidence['sources']` names where they came from, so nothing in the payload is a black box.
 */
final readonly class CreditFactor
{
    /**
     * @param  string  $key  stable machine key (also the config key for weight and cap)
     * @param  string  $label  officer-facing name
     * @param  'individual'|'contextual'|'supporting'  $group  contextual factors are capped together (§39 – §41); supporting factors carry no weight (§42)
     * @param  float  $weight  share of the 100-point score, from config('credit.weights')
     * @param  float  $value  normalised reading, 0 – 1
     * @param  float  $contribution  points contributed to the score
     * @param  float  $maxContribution  points this factor could contribute at value 1.0, after its cap
     * @param  'positive'|'negative'|'neutral'  $direction  which way the factor pushes the recommendation
     * @param  string  $summary  plain-language reading of the evidence
     * @param  array{sources: list<string>, data: array<string, mixed>, notes: list<string>}  $evidence
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $group,
        public float $weight,
        public float $value,
        public float $contribution,
        public float $maxContribution,
        public string $direction,
        public string $summary,
        public array $evidence,
    ) {}

    /**
     * Build a factor from its normalised reading, applying the configured weight and cap.
     *
     * @param  'individual'|'contextual'|'supporting'  $group
     * @param  list<string>  $sources  tables / services the figures were read from
     * @param  array<string, mixed>  $data  the raw figures behind `$value`
     * @param  list<string>  $notes  caveats, including data the specification asks for that this system does not hold
     */
    public static function make(string $key, string $label, string $group, float $value, string $summary, array $sources, array $data, array $notes = []): self
    {
        $value = max(0.0, min(1.0, $value));
        $weight = (float) config("credit.weights.{$key}", 0.0);
        $cap = config("credit.caps.{$key}");
        $effectiveWeight = $cap === null ? $weight : min($weight, (float) $cap);

        return new self(
            key: $key,
            label: $label,
            group: $group,
            weight: round($effectiveWeight, 4),
            value: round($value, 4),
            contribution: round($effectiveWeight * $value * 100, 2),
            maxContribution: round($effectiveWeight * 100, 2),
            direction: self::directionFor($value, $effectiveWeight),
            summary: $summary,
            evidence: ['sources' => $sources, 'data' => $data, 'notes' => $notes],
        );
    }

    /**
     * A SUPPORTING factor (§42): evidence shown to the officer that carries no weight and contributes nothing, regardless
     * of any weight or cap configured for its key.
     *
     * @param  list<string>  $sources
     * @param  array<string, mixed>  $data
     * @param  list<string>  $notes
     */
    public static function supporting(string $key, string $label, string $summary, array $sources, array $data, array $notes = []): self
    {
        return new self(
            key: $key,
            label: $label,
            group: 'supporting',
            weight: 0.0,
            value: 0.0,
            contribution: 0.0,
            maxContribution: 0.0,
            direction: 'neutral',
            summary: $summary,
            evidence: ['sources' => $sources, 'data' => $data, 'notes' => $notes],
        );
    }

    /**
     * A zero-weight factor can push nothing, so it always reads "neutral" (§42).
     */
    private static function directionFor(float $value, float $weight): string
    {
        if ($weight <= 0.0) {
            return 'neutral';
        }

        return match (true) {
            $value >= (float) config('credit.direction.positive_threshold') => 'positive',
            $value <= (float) config('credit.direction.negative_threshold') => 'negative',
            default => 'neutral',
        };
    }

    /**
     * Same factor with its contribution reduced — used to hold the contextual signals inside
     * `credit.caps.contextual_total` (§39 – §41).
     */
    public function withContribution(float $contribution): self
    {
        return new self($this->key, $this->label, $this->group, $this->weight, $this->value, round($contribution, 2), $this->maxContribution, $this->direction, $this->summary, $this->evidence);
    }

    /**
     * @return array{key: string, label: string, group: string, weight: float, value: float, contribution: float, max_contribution: float, direction: string, summary: string, evidence: array{sources: list<string>, data: array<string, mixed>, notes: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'group' => $this->group,
            'weight' => $this->weight,
            'value' => $this->value,
            'contribution' => $this->contribution,
            'max_contribution' => $this->maxContribution,
            'direction' => $this->direction,
            'summary' => $this->summary,
            'evidence' => $this->evidence,
        ];
    }
}
