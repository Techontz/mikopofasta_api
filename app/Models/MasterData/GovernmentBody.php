<?php

declare(strict_types=1);

namespace App\Models\MasterData;

/** Admin-managed lookup list — see MasterDataModel. A ministry or government institution — the Wizara / Taasisi ya Serikali a public servant serves. */
final class GovernmentBody extends MasterDataModel
{
    protected $table = 'government_bodies';
}
