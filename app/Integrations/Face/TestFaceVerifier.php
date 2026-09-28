<?php

namespace App\Integrations\Face;

/**
 * Deterministic test driver: passes when at least 3 decodable image frames were captured and they
 * are not all identical (identical frames = a static picture held to the camera, which must fail).
 */
class TestFaceVerifier implements FaceVerifier
{
    public const MIN_FRAMES = 3;

    public function verify(array $frames, ?string $referencePhoto = null): FaceVerificationResult
    {
        $images = array_values(array_filter($frames, fn (string $frame): bool => @getimagesizefromstring($frame) !== false));

        if (count($images) < self::MIN_FRAMES) {
            return new FaceVerificationResult(false, 0.0, null, 'Live capture needs at least '.self::MIN_FRAMES.' camera frames.');
        }

        if (count(array_unique(array_map('md5', $images))) === 1) {
            return new FaceVerificationResult(false, 0.12, null, 'Liveness failed: a static photo was detected. Live capture is required.');
        }

        return new FaceVerificationResult(true, 0.98, $referencePhoto !== null ? 0.93 : null, null, 'test-'.substr(md5(implode('', $images)), 0, 12));
    }
}
