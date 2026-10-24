<?php

use App\Providers\AppServiceProvider;
use App\Providers\OperationsServiceProvider;
use App\Providers\QueueManagementServiceProvider;

return [
    AppServiceProvider::class,
    OperationsServiceProvider::class,
    QueueManagementServiceProvider::class,
];
