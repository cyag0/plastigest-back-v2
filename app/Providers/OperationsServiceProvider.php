<?php

namespace App\Providers;

use App\Support\SystemAlerts;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Operación en producción: lo que no es del negocio pero decide si el
 * servidor se puede mantener (herramientas de desarrollo fuera de producción,
 * avisos cuando falla un trabajo de la cola o una tarea programada, etc.).
 */
class OperationsServiceProvider extends ServiceProvider
{
    /**
     * Paquetes de require-dev que solo se registran en local (proveedor =>
     * clase del paquete que necesita). Están en extra.laravel.dont-discover
     * de composer.json: en producción la imagen se instala con --no-dev, las
     * clases no existen, y Telescope no debe grabar peticiones (tokens,
     * contraseñas) en la base aunque falte TELESCOPE_ENABLED=false.
     */
    private const LOCAL_PROVIDERS = [
        \Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class => \Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class,
        \Laravel\Telescope\TelescopeServiceProvider::class => \Laravel\Telescope\TelescopeServiceProvider::class,
        // El de la app extiende una clase de Telescope: cargarlo sin el
        // paquete sería un error fatal, por eso se revisa la clase padre.
        TelescopeServiceProvider::class => \Laravel\Telescope\TelescopeApplicationServiceProvider::class,
    ];

    public function register(): void
    {
        if ($this->app->environment('local')) {
            foreach (self::LOCAL_PROVIDERS as $provider => $requires) {
                if (class_exists($requires)) {
                    $this->app->register($provider);
                }
            }
        }
    }

    public function boot(): void
    {
        // Un job que agotó sus intentos (correo del corte de caja, push, OTP)
        // o una tarea programada que falló: log critical y aviso a los
        // administradores del sistema (campana y correo), sin inundarlos.
        Queue::failing(fn(JobFailed $event) => $this->app->make(SystemAlerts::class)->jobFailed($event));
        Event::listen(
            ScheduledTaskFailed::class,
            fn(ScheduledTaskFailed $event) => $this->app->make(SystemAlerts::class)->scheduledTaskFailed($event)
        );
    }
}
