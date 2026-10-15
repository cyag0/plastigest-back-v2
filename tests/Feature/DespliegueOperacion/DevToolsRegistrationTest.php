<?php

namespace Tests\Feature\DespliegueOperacion;

use App\Providers\OperationsServiceProvider;
use Tests\TestCase;

/**
 * Telescope e IDE Helper son de require-dev: fuera de local no se registran
 * (en producción ni siquiera están instalados) y Telescope viene apagado si
 * falta TELESCOPE_ENABLED.
 */
class DevToolsRegistrationTest extends TestCase
{
    public function test_dev_tools_are_not_registered_outside_local(): void
    {
        $this->assertFalse($this->app->providerIsLoaded(\Laravel\Telescope\TelescopeServiceProvider::class));
        $this->assertFalse($this->app->providerIsLoaded(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class));
        $this->assertFalse($this->app->providerIsLoaded(\App\Providers\TelescopeServiceProvider::class));

        $this->get('/telescope')->assertNotFound();
    }

    public function test_dev_tools_are_registered_in_local(): void
    {
        $this->app['env'] = 'local';

        try {
            (new OperationsServiceProvider($this->app))->register();
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertTrue($this->app->providerIsLoaded(\Laravel\Telescope\TelescopeServiceProvider::class));
        $this->assertTrue($this->app->providerIsLoaded(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class));
        $this->assertTrue($this->app->providerIsLoaded(\App\Providers\TelescopeServiceProvider::class));
    }

    public function test_telescope_is_off_by_default_outside_local(): void
    {
        $this->assertFalse($this->telescopeEnabledWithoutVariable('production'));
        $this->assertFalse($this->telescopeEnabledWithoutVariable('testing'));
        $this->assertTrue($this->telescopeEnabledWithoutVariable('local'));
    }

    /**
     * Valor de telescope.enabled cuando TELESCOPE_ENABLED no está definida.
     */
    private function telescopeEnabledWithoutVariable(string $appEnv): bool
    {
        $saved = [];
        foreach (['TELESCOPE_ENABLED', 'APP_ENV'] as $name) {
            $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
            unset($_ENV[$name], $_SERVER[$name]);
            putenv($name);
        }

        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = $appEnv;
        putenv("APP_ENV={$appEnv}");

        try {
            return (bool) (require config_path('telescope.php'))['enabled'];
        } finally {
            foreach ($saved as $name => [$env, $server, $getenv]) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
                if ($env !== null) {
                    $_ENV[$name] = $env;
                }
                if ($server !== null) {
                    $_SERVER[$name] = $server;
                }
                if ($getenv !== false) {
                    putenv("{$name}={$getenv}");
                }
            }
        }
    }
}
