<?php

namespace App\Integrations\Nida;

/**
 * NIDA identity lookup (swappable connector selected by config('integrations.nida.driver')).
 */
interface NidaConnector
{
    /**
     * Fetch the identity registered under a 20-digit NIDA number, or null when it does not exist.
     */
    public function lookup(string $nidaNumber): ?NidaIdentity;
}
