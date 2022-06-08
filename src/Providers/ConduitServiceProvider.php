<?php

namespace Conduit\Providers;

use Conduit\Middleware\ArchiveMiddleware;
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

            if (config('app.env') == 'testing') {
                $archiveMiddleware = app(ArchiveMiddleware::class);
                $adapter->pushHandler($archiveMiddleware);
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
         */
        $logger = function (Adapter $adapter) {
            $request = parse_adapter_request($adapter);
            $response = parse_adapter_response($adapter);
            $truncate = LoggingMiddleware::getTruncate();

            if ($truncate && strlen($response['body']) > $truncate) {
                $response['body'] = substr($response['body'], 0, $truncate).'...';
            }

            app('log')->debug(print_r(compact('request', 'response'), true));
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
        $eventMiddleware = function (Adapter $adapter, callable $next): ResponseInterface {
            app('events')->dispatch('transmission-sending', $adapter);

            try {
                return $next($adapter);
            } catch (Exception $error) {
                app('events')->dispatch('transmission-failed', $adapter);

                throw $error;
            } finally {
                app('events')->dispatch('transmission-sent', $adapter);
            }
        };

        $adapter->pushHandler($eventMiddleware);
    }
}
