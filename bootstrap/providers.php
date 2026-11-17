<?php

use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    // Big Glow Day perfume store — routes, migrations, observers (app/Perfume).
    App\Perfume\PerfumeServiceProvider::class,
];
