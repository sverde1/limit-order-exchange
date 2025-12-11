<?php

use Illuminate\Support\ServiceProvider;

return ServiceProvider::defaultProviders()->merge([
    App\Providers\AppServiceProvider::class,
    App\Providers\FortifyServiceProvider::class,
    App\Providers\RouteServiceProvider::class,
])->toArray();
