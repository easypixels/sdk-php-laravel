<?php

namespace EasyPixel\Laravel;

use EasyPixel\HttpClient;
use EasyPixel\Laravel\Console\SendSceneCommand;
use Illuminate\Support\ServiceProvider;

class EasyPixelServiceProvider extends ServiceProvider
{
    /** Container alias of the matrix manager, and the facade accessor. */
    const ALIAS = 'easypixel';

    /**
     * Container key of the HttpClient factory: a callable that takes the
     * matrix configuration and returns an HttpClient. Rebind it to send
     * requests through something other than the SDK's cURL transport.
     */
    const HTTP_FACTORY = 'easypixel.http';

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/easypixel.php', 'easypixel');

        $this->app->bind(self::HTTP_FACTORY, function () {
            return function (array $config) {
                return new HttpClient(
                    $config['base_url'],
                    [],
                    (int) $config['timeout'],
                    (int) $config['connect_timeout']
                );
            };
        });

        $this->app->singleton(MatrixManager::class, function ($app) {
            return new MatrixManager($app);
        });

        $this->app->alias(MatrixManager::class, self::ALIAS);
    }

    public function boot()
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/easypixel.php' => $this->app->configPath('easypixel.php'),
        ], 'easypixel-config');

        $this->commands([
            SendSceneCommand::class,
        ]);
    }
}
