<?php

namespace Conduit\Traits;

use GuzzleHttp\Client;
use Conduit\Bridges\Bridge;
use GuzzleHttp\HandlerStack;
use Conduit\Adapters\Adapter;
use GuzzleHttp\Handler\MockHandler;
use Illuminate\Contracts\Container\Container;
use Conduit\Bridges\{MockBridge, GuzzleBridge};
use Illuminate\Contracts\Foundation\Application;

/**
 * Trait MockAdapter
 * @package Conduit\Traits
 * @property Container $app
 */
trait MockAdapter
{
    /**
     * @return void
     */
    public function setUpFakeAdapter()
    {
        if ($this->app) {
            $this->rebindAdapter($this->app);
        }

        $this->rebindAdapter(app());
    }

    /**
     * @param Container $app
     * @return GuzzleBridge
     */
    public function rebindAdapter(Container $app): Bridge
    {
        $handler = $app->make(MockHandler::class);
        $bridge = $app->make(GuzzleBridge::class)->setHandler($handler);
        $middleware = $app->make(Adapter::class)->getHandlers();

        $app->bind(Client::class, function (Application $app, array $args = []) use ($handler) {
            $args = compile_arguments(Client::class, $args);
            $handler = HandlerStack::create($handler);
            $args['config']['handler'] = $handler;
            $args = array_values($args);

            return new Client(...$args);
        });

        $app->bind(GuzzleBridge::class, function (Application $app, array $args = []) use ($handler) {
            $args = compile_arguments(GuzzleBridge::class, $args);
            $args = array_values($args);
            $args[0] = $args[0] ?? new Adapter;

            return (new MockBridge(...$args))->setHandler($handler);
        });

        $mockBridge = app(MockBridge::class);   //This may seem unnecessary, but it's needed in case of serialization
        $app->bind(Adapter::class, function (Application $app, array $args = []) use ($middleware, $mockBridge) {
            $args = compile_arguments(Adapter::class, $args);
            $args = array_values($args);
            $adapter = (new Adapter(...$args))->setHandlers($middleware);

            $mockBridge->setAdapter($adapter);
            $adapter->setBridge($mockBridge);

            return $adapter;
        });

        return $bridge;
    }
}
