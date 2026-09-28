<?php

namespace App\Integrations\Face;

final readonly class FaceVerificationResult
{
    public function __construct(
        public bool $passed,
        public float $livenessScore,
        public ?float $matchScore = null,
        public ?string $reason = null,
        public ?string $reference = null,
    ) {}
}
