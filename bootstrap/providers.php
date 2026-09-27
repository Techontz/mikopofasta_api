<?php

use App\Providers\AppServiceProvider;
use App\Providers\CustomersServiceProvider;
use App\Providers\LoansServiceProvider;
use App\Providers\PaymentsServiceProvider;

return [
    AppServiceProvider::class,
    CustomersServiceProvider::class,
    PaymentsServiceProvider::class,
    LoansServiceProvider::class,
];
