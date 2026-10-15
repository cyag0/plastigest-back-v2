<?php

namespace App\Providers;

use App\Models\Admin\Location;
use App\Policies\LocationPolicy;
use App\Support\Activity\ActivityObserver;
use App\Support\Activity\ActivitySubjects;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Location::class => LocationPolicy::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Register policies
        foreach ($this->policies as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Bitácora ("Mi actividad"): cada alta, edición y baja de estos modelos.
        foreach (array_keys(ActivitySubjects::all()) as $model) {
            $model::observe(ActivityObserver::class);
        }

        $this->configureAuthRateLimiters();
    }

    /**
     * Límites de las rutas públicas de autenticación (routes/api.php).
     *
     * Antes usaban throttle:N,1, que comparte UN contador por IP entre las
     * cuatro rutas: tres contraseñas mal escritas bloqueaban "Olvidé mi
     * contraseña", y toda una sucursal (misma IP) compartía 5 intentos por
     * minuto. Ahora cada ruta tiene su contador, por correo y por IP; el
     * límite por correo vale desde cualquier IP, así que repartir los
     * intentos entre muchas IPs ya no multiplica las oportunidades.
     *
     * Los intentos fallidos de código de recuperación también se cuentan por
     * correo en PasswordResetController, sin reiniciarse al pedir otro código.
     */
    private function configureAuthRateLimiters(): void
    {
        $email = fn(Request $request) => is_string($request->input('email'))
            ? mb_strtolower(trim($request->input('email')))
            : '';

        RateLimiter::for('auth-login', fn(Request $request) => [
            Limit::perMinute(5)->by('email-ip:' . $email($request) . '|' . $request->ip()),
            Limit::perHour(30)->by('email:' . $email($request)),
            Limit::perMinute(30)->by('ip:' . $request->ip()),
        ]);

        // Cada solicitud manda un correo: pocas por correo (también por día,
        // para no agotar el envío de correos del sistema) y por IP.
        RateLimiter::for('auth-forgot-password', fn(Request $request) => [
            Limit::perHour(3)->by('email:' . $email($request)),
            Limit::perDay(10)->by('email-day:' . $email($request)),
            Limit::perHour(20)->by('ip:' . $request->ip()),
        ]);

        RateLimiter::for('auth-verify-reset-code', fn(Request $request) => [
            Limit::perMinute(5)->by('email:' . $email($request)),
            Limit::perMinute(10)->by('ip:' . $request->ip()),
        ]);

        RateLimiter::for('auth-reset-password', fn(Request $request) => [
            Limit::perMinute(5)->by('email:' . $email($request)),
            Limit::perMinute(10)->by('ip:' . $request->ip()),
        ]);
    }
}
