<?php

namespace Conduit\Providers;

use Exception;
use Conduit\Adapters\Adapter;
use Psr\Http\Message\ResponseInterface;
use Illuminate\Support\ServiceProvider;
use Conduit\Middleware\LoggingMiddleware;
use Illuminate\Contracts\Foundation\Application;

/**
 * Class ConceptServiceProvider
 * @package Concept\Providers
 */
class ConduitServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @void
     */
    public function boot()
    {
        $source = realpath($raw = __DIR__ . '/../../config/conduit.php') ?: $raw;
        $this->publishes([$source => config_path('conduit.php')]);
        $this->mergeConfigFrom($source, 'conduit');
    }

    /**
     * @void
     */
    public function register()
    {
        $this->app->bind(Adapter::class, function (Application $app, array $args) {
            $args = array_values(compile_arguments(Adapter::class, $args));
            $adapter = new Adapter(...$args);

            $this->applyEvents($adapter, $app);

            if (config('app.debug')) {
                $this->applyLogger($adapter, $app);
            }

            return $adapter;
        });
    }

    /**
     * @param Adapter $adapter
     * @param Application $app
     */
    protected function applyLogger(Adapter $adapter, Application $app)
    {
        /**
         * @param Adapter $adapter
         * @param ResponseInterface $response
         */
        $logger = function (Adapter $adapter, ResponseInterface $response) use ($app) {
            $app->make('log')->debug(print_r(compact('adapter', 'response'), true));
        };

        $loggingMiddleware = app(LoggingMiddleware::class, compact('logger'));

        $adapter->pushHandler($loggingMiddleware);
    }

    /**
     * @param Adapter $adapter
     * @param Application $app
     */
    protected function applyEvents(Adapter $adapter, Application $app)
    {
        /**
         * @param Adapter $adapter
         * @param callable $next
         * @return ResponseInterface
         */
        $eventMiddleware = function (Adapter $adapter, callable $next) use ($app) {
            $app->make('events')->dispatch('transmission-sending', $adapter);

            $results = null;
            try {
                return $next($adapter);
            } catch (Exception $error) {
                $app->make('events')->dispatch('transmission-failed', $adapter);

                throw $error;
            } finally {
                $app->make('events')->dispatch('transmission-sent', $adapter);
            }
        };

        $adapter->pushHandler($eventMiddleware);
    }
}
