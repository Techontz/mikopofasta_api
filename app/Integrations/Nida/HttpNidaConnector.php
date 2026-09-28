<?php

namespace App\Integrations\Nida;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real NIDA gateway stub. Inferred: POST {base_url}/lookup {nin} with a bearer key returning
 * FIRSTNAME / MIDDLENAME / SURNAME / DATEOFBIRTH / SEX / PHONE / PHOTO; map to the contracted API when issued.
 */
class HttpNidaConnector implements NidaConnector
{
    public function lookup(string $nidaNumber): ?NidaIdentity
    {
        $config = config('integrations.nida');

        if (empty($config['base_url']) || empty($config['api_key'])) {
            throw new RuntimeException('NIDA gateway is not configured.');
        }

        $response = Http::withToken($config['api_key'])->timeout(20)->post(rtrim($config['base_url'], '/').'/lookup', ['nin' => $nidaNumber]);

        if ($response->notFound()) {
            return null;
        }

        $data = $response->throw()->json();

        return new NidaIdentity(
            nidaNumber: $nidaNumber,
            firstName: strtoupper((string) ($data['FIRSTNAME'] ?? '')),
            middleName: strtoupper((string) ($data['MIDDLENAME'] ?? '')),
            lastName: strtoupper((string) ($data['SURNAME'] ?? '')),
            dateOfBirth: (string) ($data['DATEOFBIRTH'] ?? ''),
            gender: strtolower((string) ($data['SEX'] ?? '')) === 'female' ? 'female' : 'male',
            phone: (string) ($data['PHONE'] ?? ''),
            photo: isset($data['PHOTO']) ? 'data:image/jpeg;base64,'.$data['PHOTO'] : null,
        );
    }
}
