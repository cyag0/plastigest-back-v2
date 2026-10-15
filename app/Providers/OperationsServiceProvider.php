<?php

namespace App\Providers;

use App\Support\SystemAlerts;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Http\Request;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Resend\Client as ResendClient;
use Resend\Transporters\HttpTransporter;
use Resend\ValueObjects\ApiKey;
use Resend\ValueObjects\Transporter\BaseUri;
use Resend\ValueObjects\Transporter\Headers;

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

        // Resend con timeouts: el SDK arma su cliente Guzzle sin límite y un
        // proveedor lento dejaba colgado al worker (el correo de las
        // notificaciones se manda dentro del SendNotificationJob) hasta que
        // --timeout lo mataba. Mismo cliente que Resend::client(), con límite.
        Mail::extend('resend', function (array $config) {
            $timeout = (float) ($config['timeout'] ?? 15);

            return new ResendTransport(new ResendClient(new HttpTransporter(
                new GuzzleClient(['connect_timeout' => min(5.0, $timeout), 'timeout' => $timeout]),
                BaseUri::from(getenv('RESEND_BASE_URL') ?: 'api.resend.com'),
                Headers::withAuthorization(ApiKey::from((string) ($config['key'] ?? config('services.resend.key')))),
            )));
        });

        // Reportes de errores de la app (POST auth/admin/client-errors): un
        // error en un render puede repetirse en bucle; 20 por minuto por
        // usuario bastan para diagnosticar sin llenar el log.
        RateLimiter::for(
            'client-errors',
            fn(Request $request) => Limit::perMinute(20)->by('client-errors:' . ($request->user()?->id ?: $request->ip()))
        );
    }
}
