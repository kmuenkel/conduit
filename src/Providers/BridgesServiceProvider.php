<?php

namespace Conduit\Providers;

use Http\Client\HttpClient;
use Conduit\Drivers\Driver;
use Conduit\Drivers\GuzzleDriver;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Http\Message\RequestInterface;
use Illuminate\Support\ServiceProvider;
use GuzzleHttp\Cookie\CookieJarInterface;
use Illuminate\Contracts\Container\Container;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Http\Adapter\Guzzle6\Client as GuzzleAdapter;
use GuzzleHttp\Cookie\CookieJar as GuzzleCookieJar;
use GuzzleHttp\ClientInterface as GuzzleClientInterface;

class BridgesServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $source = realpath($raw = __DIR__ . '/../../config/guzzle.php') ?: $raw;
        $this->publishes([$source => config_path('guzzle.php')]);
        $this->mergeConfigFrom($source, 'guzzle');
    }
    
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $guzzleClosure = function (Container $app, array $params = []) {
            return app(GuzzleDriver::class)->makeClient(array_merge(
                $app->make('config')->get('guzzle', []),
                array_get($params, 'config', [])
            ));
        };
        
        $this->app->bind(GuzzleClient::class, $guzzleClosure);
        $this->app->bind(GuzzleClientInterface::class, $guzzleClosure);
        $this->app->bind(HttpClient::class, GuzzleAdapter::class);
        $this->app->bind(Driver::class, GuzzleDriver::class);
        $this->app->bind(RequestInterface::class, GuzzleRequest::class);
        $this->app->bind(CookieJarInterface::class, function (Container $app, array $params = []) {
            return new GuzzleCookieJar(
                #array_get($params, 'strictMode', true),
                array_get($params, 'strictMode', false),
                array_get($params, 'cookieArray', [])
            );
        });
    }
}
