<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

abstract class Controller
{
    protected function currentEmployee(): Employee
    {
        /** @var Employee */
        return auth()->user();
    }

    protected function currentCompany(): Company
    {
        return $this->currentEmployee()->company;
    }

    /**
     * @return Collection<int, Branch>
     */
    protected function companyBranches(): Collection
    {
        return Branch::where('company_id', $this->currentEmployee()->company_id)->orderBy('id')->get();
    }
}
