<?php

declare(strict_types=1);

namespace App\Models\MasterData;

/** Admin-managed lookup list — see MasterDataModel. A line of trade — the Sekta ya Biashara a trader operates in. */
final class BusinessSector extends MasterDataModel
{
    protected $table = 'business_sectors';
}
