<?php

namespace App\Integrations\Face;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real liveness provider stub. Inferred: POST {base_url}/liveness with base64 frames (+ reference)
 * returning {passed, liveness_score, match_score, reason, reference}.
 */
class HttpFaceVerifier implements FaceVerifier
{
    public function verify(array $frames, ?string $referencePhoto = null): FaceVerificationResult
    {
        $config = config('integrations.face');

        if (empty($config['base_url']) || empty($config['api_key'])) {
            throw new RuntimeException('Face verification provider is not configured.');
        }

        $data = Http::withToken($config['api_key'])
            ->timeout(30)
            ->post(rtrim($config['base_url'], '/').'/liveness', [
                'frames' => array_map('base64_encode', $frames),
                'reference' => $referencePhoto !== null ? base64_encode($referencePhoto) : null,
            ])
            ->throw()
            ->json();

        return new FaceVerificationResult(
            (bool) ($data['passed'] ?? false),
            (float) ($data['liveness_score'] ?? 0),
            isset($data['match_score']) ? (float) $data['match_score'] : null,
            $data['reason'] ?? null,
            $data['reference'] ?? null,
        );
    }
}
