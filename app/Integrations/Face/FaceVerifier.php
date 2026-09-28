<?php

namespace App\Integrations\Face;

/**
 * Face liveness (and optional NIDA photo match) provider, selected by config('integrations.face.driver').
 */
interface FaceVerifier
{
    /**
     * @param  list<string>  $frames  Binary image frames captured live from the camera, in order.
     * @param  string|null  $referencePhoto  NIDA photo (binary) to match against, when available.
     */
    public function verify(array $frames, ?string $referencePhoto = null): FaceVerificationResult;
}
