<?php

declare(strict_types=1);

namespace App\Models\MasterData;

/**
 * A private-sector employer — see MasterDataModel.
 *
 * Deliberately NOT the same list as `sectors`. A ministry has cadres inside
 * it; a company does not, and putting both in one table would offer a public
 * servant a sugar mill to serve in.
 */
final class Employer extends MasterDataModel
{
    protected $table = 'employers';
}
