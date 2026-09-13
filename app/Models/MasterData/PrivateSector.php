<?php

declare(strict_types=1);

namespace App\Models\MasterData;

/** Admin-managed lookup list — see MasterDataModel. A private-sector industry — the Sekta a private employee works in. */
final class PrivateSector extends MasterDataModel
{
    protected $table = 'private_sectors';
}
