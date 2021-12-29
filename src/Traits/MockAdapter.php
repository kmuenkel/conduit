<?php

namespace Conduit\Traits;

use GuzzleHttp\Client;
use Conduit\Bridges\Bridge;
use GuzzleHttp\HandlerStack;
use Conduit\Adapters\Adapter;
use Conduit\Bridges\MockBridge;
use Conduit\Bridges\GuzzleBridge;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;

/**
 * Trait MockAdapter
 * @package Conduit\Traits
 * @property Container $app
 */
trait MockAdapter
{
    /**
     * @return Bridge
     */
    public function setUpFakeAdapter()
    {
        $handler = app(MockHandler::class);
        $bridge = app(GuzzleBridge::class)->setHandler($handler);

        if ($this->app) {
            $this->app->bind(Client::class, function (Application $app, array $args = []) use ($handler) {
                $args = compile_arguments(Client::class, $args);
                $handler = HandlerStack::create($handler);
                $args['config']['handler'] = $handler;
                $args = array_values($args);

                return new Client(...$args);
            });

            $this->app->bind(GuzzleBridge::class, function (Application $app, array $args = []) use ($bridge) {
                return $bridge;
            });

            $middleware = app(Adapter::class)->getHandlers();
            $this->app->bind(Adapter::class, function (Application $app, array $args = []) use ($middleware) {
                $args = compile_arguments(Adapter::class, $args);
                $args = array_values($args);
                $adapter = (new Adapter(...$args))->setHandlers($middleware);

                $mockBridge = app(MockBridge::class, compact('adapter'));
                $adapter->setBridge($mockBridge);

                return $adapter;
            });
        }

        return $bridge;
    }
}
