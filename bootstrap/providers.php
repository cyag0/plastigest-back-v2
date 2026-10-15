<?php

return [
    App\Providers\AppServiceProvider::class,
    // Herramientas de desarrollo (Telescope, IDE Helper) solo en local, y la
    // operación en producción (ver la clase).
    App\Providers\OperationsServiceProvider::class,
];
