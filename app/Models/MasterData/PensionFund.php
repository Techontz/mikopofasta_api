<?php

declare(strict_types=1);

namespace App\Models\MasterData;

/** Admin-managed lookup list — see MasterDataModel. A social security fund (Mfuko wa Hifadhi ya Jamii) — PSSSF, NSSF and the rest. */
final class PensionFund extends MasterDataModel
{
    protected $table = 'pension_funds';
}
