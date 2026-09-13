<?php

declare(strict_types=1);

namespace App\Models\MasterData;

/** Admin-managed lookup list — see MasterDataModel. A university or college (Chuo). */
final class College extends MasterDataModel
{
    protected $table = 'colleges';
}
